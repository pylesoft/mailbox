<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Exceptions\OAuthEnrollmentException;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxOAuthToken;
use Pyle\Mailbox\Services\OAuth\GmailUserOAuthService;
use Pyle\Mailbox\Services\OAuth\MsGraphUserOAuthService;

beforeEach(function (): void {
    config()->set([
        'mailbox.oauth.gmail.client_id' => 'selected-client',
        'mailbox.oauth.gmail.client_secret' => 'private-client-secret',
        'mailbox.oauth.gmail.redirect_uri' => 'https://host.example/oauth/google',
        'mailbox.drivers.ms-graph.tenant_id' => 'common',
        'mailbox.drivers.ms-graph.client_id' => 'selected-client',
        'mailbox.drivers.ms-graph.client_secret' => 'private-client-secret',
        'mailbox.oauth.ms_graph.redirect_uri' => 'https://host.example/oauth/microsoft',
    ]);
    Http::preventStrayRequests();
});

it('rejects a legacy callback before it can claim another connections or unowned credentials', function (string $driver, string $serviceClass): void {
    $original = MailboxConnection::query()->create(['name' => 'Original', 'driver' => $driver]);
    $target = MailboxConnection::query()->create(['name' => 'Target', 'driver' => $driver]);
    foreach ([$original->id, null] as $connectionId) {
        MailboxOAuthToken::query()->create([
            'mailbox_connection_id' => $connectionId, 'provider' => $driver.'-user',
            'external_user_id' => 'same-subject', 'email' => 'same@example.com',
            'access_token' => 'historical-access', 'refresh_token' => 'historical-refresh',
        ]);
    }
    $before = MailboxOAuthToken::query()->get()->map->getAttributes()->all();
    $service = app($serviceClass);
    Cache::put($service->stateKey('old-state'), [
        'connection_id' => $target->id, 'return_to' => '/settings/mail', 'user_reference' => 'untrusted-actor',
    ], now()->addMinutes(5));
    Http::fake([
        '*token' => Http::response(['access_token' => 'replacement-access', 'refresh_token' => 'replacement-refresh']),
        '*userinfo' => Http::response(['sub' => 'same-subject', 'email' => 'same@example.com']),
        '*me*' => Http::response(['id' => 'same-subject', 'mail' => 'same@example.com']),
    ]);

    expect(fn () => $service->handleCallback('old-state', 'private-code'))
        ->toThrow(OAuthEnrollmentException::class, 'Reconnect');
    expect(MailboxOAuthToken::query()->get()->map->getAttributes()->all())->toBe($before)
        ->and($service->stateContext('old-state'))->toBe(['return_to' => '/settings/mail', 'user_reference' => 'untrusted-actor']);
    Http::assertNothingSent();
})->with([
    'Gmail' => ['gmail', GmailUserOAuthService::class],
    'Microsoft' => ['ms-graph', MsGraphUserOAuthService::class],
]);

it('keeps legacy recovery values out of setup exception traces with argument capture enabled', function (string $serviceClass): void {
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    try {
        $failure = null;
        try {
            app($serviceClass)->handleCallback('private-recovery-state', 'private-authorization-code');
        } catch (OAuthEnrollmentException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(OAuthEnrollmentException::class);
        $arguments = array_map(static fn (array $frame): array => array_filter($frame['args'] ?? [], is_string(...)), $failure?->getTrace() ?? []);
        foreach ([print_r($arguments, true), $failure?->getTraceAsString()] as $diagnostic) {
            expect($diagnostic)->not->toContain('private-recovery-state');
            expect($diagnostic)->not->toContain('private-authorization-code');
        }
    } finally {
        if (is_string($previous)) {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
    Http::assertNothingSent();
})->with([GmailUserOAuthService::class, MsGraphUserOAuthService::class]);

