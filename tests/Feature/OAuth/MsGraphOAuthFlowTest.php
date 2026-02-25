<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Enums\ConnectionStatus;
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

it('redirect endpoint builds microsoft authorize url and stores state payload', function (): void {
    $response = $this->get('/mailbox/oauth/ms-graph/redirect?mailbox_connection_id=42&return_to=%2Fdashboard&user_reference=user-77');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect(is_string($location))->toBeTrue();

    $parts = parse_url((string) $location);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['host'] ?? null)->toBe('login.microsoftonline.com');
    expect($parts['path'] ?? null)->toContain('/oauth2/v2.0/authorize');
    expect($query['client_id'] ?? null)->toBe('client-test');
    expect($query['scope'] ?? null)->toContain('offline_access');
    expect($query['state'] ?? null)->toBeString()->not->toBe('');

    $service = app(MsGraphUserOAuthService::class);
    $payload = Cache::get($service->stateKey((string) $query['state']));

    expect($payload)->toBeArray();
    expect($payload['connection_id'] ?? null)->toBe(42);
    expect($payload['return_to'] ?? null)->toBe('/dashboard');
    expect($payload['user_reference'] ?? null)->toBe('user-77');
});

it('callback exchanges code and stores oauth token in database', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Conn',
        'driver' => 'ms-graph',
        'status' => ConnectionStatus::CONNECTED,
    ]);

    $service = app(MsGraphUserOAuthService::class);
    $state = 'state-abc';

    Cache::put($service->stateKey($state), [
        'connection_id' => $connection->id,
        'return_to' => '/oauth/result',
        'user_reference' => 'web-user-1',
    ], now()->addMinutes(5));

    $idTokenPayload = [
        'oid' => 'graph-user-1',
        'preferred_username' => 'user@example.com',
        'tid' => 'tenant-test',
    ];
    $encodedPayload = rtrim(strtr(base64_encode((string) json_encode($idTokenPayload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $idToken = 'header.'.$encodedPayload.'.signature';

    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
            'access_token' => 'access-token-1',
            'refresh_token' => 'refresh-token-1',
            'token_type' => 'Bearer',
            'scope' => 'openid profile offline_access Mail.ReadWrite',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ], 200),
        'https://graph.microsoft.com/v1.0/me*' => Http::response([
            'id' => 'graph-user-1',
            'mail' => 'user@example.com',
            'displayName' => 'Mailbox User',
        ], 200),
    ]);

    $response = $this->get('/mailbox/oauth/ms-graph/callback?state='.$state.'&code=oauth-code-1');

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/oauth/result?');
    expect($location)->toContain('mailbox_oauth=success');
    expect($location)->toContain('user_reference=web-user-1');

    $token = MailboxOAuthToken::query()->first();

    expect($token)->not->toBeNull();
    expect($token?->provider)->toBe('ms-graph-user');
    expect($token?->mailbox_connection_id)->toBe($connection->id);
    expect($token?->external_user_id)->toBe('graph-user-1');
    expect($token?->email)->toBe('user@example.com');
    expect($token?->access_token)->toBe('access-token-1');
    expect($token?->refresh_token)->toBe('refresh-token-1');
    expect($token?->scopes)->toContain('Mail.ReadWrite');
    expect($token?->meta)->toBeArray();

    expect(Cache::get($service->stateKey($state)))->toBeNull();
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
