<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Enums\ConnectionStatus;
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

it('redirect endpoint builds google authorize url and stores state payload', function (): void {
    $response = $this->get('/mailbox/oauth/gmail/redirect?mailbox_connection_id=42&return_to=%2Fdashboard&user_reference=user-77');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect(is_string($location))->toBeTrue();

    $parts = parse_url((string) $location);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['host'] ?? null)->toBe('accounts.google.com');
    expect($parts['path'] ?? null)->toContain('/o/oauth2/v2/auth');
    expect($query['client_id'] ?? null)->toBe('google-client-test');
    expect($query['state'] ?? null)->toBeString()->not->toBe('');

    $service = app(GmailUserOAuthService::class);
    $payload = Cache::get($service->stateKey((string) $query['state']));

    expect($payload)->toBeArray();
    expect($payload['connection_id'] ?? null)->toBe(42);
    expect($payload['return_to'] ?? null)->toBe('/dashboard');
    expect($payload['user_reference'] ?? null)->toBe('user-77');
});

it('callback exchanges code and stores gmail oauth token in database', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Conn',
        'driver' => 'gmail',
        'status' => ConnectionStatus::CONNECTED,
    ]);

    $service = app(GmailUserOAuthService::class);
    $state = 'state-abc';

    Cache::put($service->stateKey($state), [
        'connection_id' => $connection->id,
        'return_to' => '/oauth/result',
        'user_reference' => 'web-user-1',
    ], now()->addMinutes(5));

    $idTokenPayload = [
        'sub' => 'google-user-1',
        'email' => 'user@example.com',
    ];
    $encodedPayload = rtrim(strtr(base64_encode((string) json_encode($idTokenPayload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $idToken = 'header.'.$encodedPayload.'.signature';

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access-token-1',
            'refresh_token' => 'refresh-token-1',
            'token_type' => 'Bearer',
            'scope' => 'openid profile email https://www.googleapis.com/auth/gmail.modify',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ], 200),
        'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
            'sub' => 'google-user-1',
            'email' => 'user@example.com',
            'name' => 'Mailbox User',
        ], 200),
    ]);

    $response = $this->get('/mailbox/oauth/gmail/callback?state='.$state.'&code=oauth-code-1');

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/oauth/result?');
    expect($location)->toContain('mailbox_oauth=success');
    expect($location)->toContain('user_reference=web-user-1');

    $token = MailboxOAuthToken::query()->first();

    expect($token)->not->toBeNull();
    expect($token?->provider)->toBe('gmail-user');
    expect($token?->mailbox_connection_id)->toBe($connection->id);
    expect($token?->external_user_id)->toBe('google-user-1');
    expect($token?->email)->toBe('user@example.com');
    expect($token?->access_token)->toBe('access-token-1');
    expect($token?->refresh_token)->toBe('refresh-token-1');
    expect($token?->scopes)->toContain('https://www.googleapis.com/auth/gmail.modify');

    expect(Cache::get($service->stateKey($state)))->toBeNull();
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
