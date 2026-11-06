<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\MailboxServiceProvider;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxOAuthToken;
use Pyle\Mailbox\Services\OAuth\GmailUserOAuthService;

beforeEach(function (): void {
    config()->set('mailbox.oauth.enabled', true);
    config()->set('mailbox.oauth.default_return_url', '/oauth/complete');
    config()->set('mailbox.oauth.gmail.client_id', 'google-client-test');
    config()->set('mailbox.oauth.gmail.client_secret', 'google-secret-test');
    config()->set('mailbox.oauth.gmail.redirect_uri', 'https://app.example.com/mailbox/oauth/gmail/callback');

    if (! app('router')->has('mailbox.oauth.gmail.redirect')) {
        (new MailboxServiceProvider(app()))->boot();
    }
});

it('legacy redirect returns setup guidance without creating state or accepting query ownership', function (): void {
    Http::preventStrayRequests();
    $connection = MailboxConnection::query()->create(['name' => 'Retained', 'driver' => 'gmail']);
    $before = $connection->fresh()?->getAttributes();

    $response = $this->get('/mailbox/oauth/gmail/redirect?'.http_build_query([
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
    $service = app(GmailUserOAuthService::class);
    Cache::put($service->stateKey('old-state'), [
        'connection_id' => 42, 'return_to' => '/oauth/result', 'user_reference' => 'web-user-1',
    ], now()->addMinutes(5));
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => 'private-provider-error', 'access_token' => 'private-access'], 400)]);

    $response = $this->get('/mailbox/oauth/gmail/callback?state=old-state&code=private-code');

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
it('callback redirects with oauth error query for gmail provider errors', function (): void {
    $service = app(GmailUserOAuthService::class);
    $state = 'state-error';

    Cache::put($service->stateKey($state), [
        'return_to' => '/oauth/result',
        'user_reference' => 'web-user-2',
    ], now()->addMinutes(5));

    $response = $this->get('/mailbox/oauth/gmail/callback?state='.$state.'&error=access_denied&error_description=User%20cancelled');

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/oauth/result?');
    expect($location)->toContain('mailbox_oauth=error');
    expect($location)->toContain('mailbox_oauth_error=access_denied');
    expect($location)->toContain('user_reference=web-user-2');
});
