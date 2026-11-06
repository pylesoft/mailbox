<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\MailboxServiceProvider;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxOAuthToken;
use Pyle\Mailbox\Services\OAuth\MsGraphUserOAuthService;

beforeEach(function (): void {
    config()->set('mailbox.oauth.enabled', true);
    config()->set('mailbox.oauth.default_return_url', '/oauth/complete');
    config()->set('mailbox.drivers.ms-graph.tenant_id', 'tenant-test');
    config()->set('mailbox.drivers.ms-graph.client_id', 'client-test');
    config()->set('mailbox.drivers.ms-graph.client_secret', 'secret-test');
    config()->set('mailbox.oauth.ms_graph.redirect_uri', 'https://app.example.com/mailbox/oauth/ms-graph/callback');

    if (! app('router')->has('mailbox.oauth.ms-graph.redirect')) {
        (new MailboxServiceProvider(app()))->boot();
    }
});

it('legacy redirect returns setup guidance without creating state or accepting query ownership', function (): void {
    Http::preventStrayRequests();
    $connection = MailboxConnection::query()->create(['name' => 'Retained', 'driver' => 'ms-graph']);
    $before = $connection->fresh()?->getAttributes();

    $response = $this->get('/mailbox/oauth/ms-graph/redirect?'.http_build_query([
        'mailbox_connection_id' => $connection->id, 'return_to' => '//evil.example', 'user_reference' => 'claimed-owner',
    ]));

    $response->assertStatus(400)->assertHeaderMissing('Location')->assertJson([
        'success' => false, 'error' => 'oauth_setup_required',
    ]);
    expect($response->json('error_description'))->toContain('Reconnect');
    expect(Cache::getStore()->all())->toBe([])
        ->and($connection->fresh()?->getAttributes())->toBe($before)
        ->and(MailboxOAuthToken::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('legacy callback returns reconnect guidance through safe context without an exchange', function (): void {
    $service = app(MsGraphUserOAuthService::class);
    Cache::put($service->stateKey('old-state'), [
        'connection_id' => 42, 'return_to' => '/oauth/result', 'user_reference' => 'web-user-1',
    ], now()->addMinutes(5));
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => 'private-provider-error', 'access_token' => 'private-access'], 400)]);

    $response = $this->get('/mailbox/oauth/ms-graph/callback?state=old-state&code=private-code');

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($location)->toContain('/oauth/result?');
    expect($query)->toMatchArray([
        'mailbox_oauth' => 'error', 'mailbox_oauth_error' => 'oauth_callback_failed', 'user_reference' => 'web-user-1',
    ]);
    expect($query['mailbox_oauth_error_description'])->toContain('Reconnect');
    foreach (['private-code', 'private-provider-error', 'private-access'] as $secret) {
        expect($location)->not->toContain($secret);
    }
    expect(MailboxOAuthToken::query()->count())->toBe(0);
    Http::assertNothingSent();
});
it('callback redirects with oauth error query when provider sends error', function (): void {
    $service = app(MsGraphUserOAuthService::class);
    $state = 'state-error';

    Cache::put($service->stateKey($state), [
        'return_to' => '/oauth/result',
        'user_reference' => 'web-user-2',
    ], now()->addMinutes(5));

    $response = $this->get('/mailbox/oauth/ms-graph/callback?state='.$state.'&error=access_denied&error_description=User%20cancelled');

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/oauth/result?');
    expect($location)->toContain('mailbox_oauth=error');
    expect($location)->toContain('mailbox_oauth_error=access_denied');
    expect($location)->toContain('user_reference=web-user-2');
});

it('callback returns json error when no state context is available', function (): void {
    $response = $this->get('/mailbox/oauth/ms-graph/callback?state=missing&code=abc');

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'error' => 'oauth_callback_failed',
        ]);
});

it('callback returns validation error for missing code or state', function (): void {
    $response = $this->get('/mailbox/oauth/ms-graph/callback');

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => 'missing_code_or_state',
        ]);
});
