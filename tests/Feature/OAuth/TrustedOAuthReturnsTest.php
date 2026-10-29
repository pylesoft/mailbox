<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\MailboxServiceProvider;

beforeEach(function (): void {
    config()->set([
        'mailbox.oauth.enabled' => true,
        'mailbox.oauth.default_return_url' => '/oauth/complete',
        'mailbox.oauth.allowed_return_hosts' => ['app.example.com'],
        'mailbox.oauth.gmail.client_id' => 'google-client-test',
        'mailbox.oauth.gmail.client_secret' => 'google-secret-test',
        'mailbox.oauth.gmail.redirect_uri' => 'https://app.example.com/mailbox/oauth/gmail/callback',
        'mailbox.drivers.ms-graph.tenant_id' => 'tenant-test',
        'mailbox.drivers.ms-graph.client_id' => 'microsoft-client-test',
        'mailbox.drivers.ms-graph.client_secret' => 'microsoft-secret-test',
        'mailbox.oauth.ms_graph.redirect_uri' => 'https://app.example.com/mailbox/oauth/ms-graph/callback',
    ]);

    (new MailboxServiceProvider(app()))->boot();

    Http::preventStrayRequests();
    $tokenResponse = fn (Request $request): PromiseInterface => $request['code'] === 'invalid-code'
        ? Http::response(['error' => 'invalid_grant'], 400)
        : Http::response(['access_token' => 'access-token-1']);
    Http::fake([
        'https://oauth2.googleapis.com/token' => $tokenResponse,
        'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
            'sub' => 'google-user-1',
            'email' => 'user@example.com',
        ]),
        'https://login.microsoftonline.com/tenant-test/oauth2/v2.0/token' => $tokenResponse,
        'https://graph.microsoft.com/v1.0/me*' => Http::response([
            'id' => 'microsoft-user-1',
            'mail' => 'user@example.com',
        ]),
    ]);
});

dataset('return providers', ['gmail', 'ms-graph']);
dataset('return outcomes', ['success', 'cancelled', 'exchange failure', 'missing code']);
dataset('return destinations', [
    'local path' => ['/settings/mail', 'http://localhost/settings/mail'],
    'local query' => ['/settings/mail?tab=oauth', 'http://localhost/settings/mail?tab=oauth'],
    'trusted absolute' => ['https://app.example.com/settings/mail', 'https://app.example.com/settings/mail'],
    'trusted host case and port' => ['https://APP.EXAMPLE.COM:8443/settings/mail', 'https://APP.EXAMPLE.COM:8443/settings/mail'],
    'untrusted absolute' => ['https://attacker.example/collect', 'http://localhost/oauth/complete'],
    'scheme relative' => ['//attacker.example/collect', 'http://localhost/oauth/complete'],
    'multiple slashes' => ['///attacker.example/collect', 'http://localhost/oauth/complete'],
    'slash backslash' => ['/\\attacker.example/collect', 'http://localhost/oauth/complete'],
    'backslash slash' => ['\\/attacker.example/collect', 'http://localhost/oauth/complete'],
    'backslashes' => ['\\\\attacker.example/collect', 'http://localhost/oauth/complete'],
    'absolute backslash authority' => ['https://attacker.example\\@app.example.com/collect', 'http://localhost/oauth/complete'],
    'tab between slashes' => ["/\t/attacker.example/collect", 'http://localhost/oauth/complete'],
    'newline between slashes' => ["/\n/attacker.example/collect", 'http://localhost/oauth/complete'],
    'carriage return between slashes' => ["/\r/attacker.example/collect", 'http://localhost/oauth/complete'],
    'null byte in path' => ["/settings/\0mail", 'http://localhost/oauth/complete'],
    'delete byte in path' => ["/settings/\x7fmail", 'http://localhost/oauth/complete'],
]);

it('returns OAuth attempts only to permitted application destinations', function (string $provider, string $outcome, string $returnTo, string $expectedUrl): void {
    $authorization = $this->get('/mailbox/oauth/'.$provider.'/redirect?'.http_build_query([
        'return_to' => $returnTo,
        'user_reference' => 'web-user-1',
    ]));
    $authorization->assertRedirect();
    parse_str((string) parse_url((string) $authorization->headers->get('Location'), PHP_URL_QUERY), $authorizationQuery);

    $callbackQuery = ['state' => $authorizationQuery['state']];

    if ($outcome === 'cancelled') {
        $callbackQuery['error'] = 'access_denied';
        $callbackQuery['error_description'] = 'User cancelled';
    } elseif ($outcome !== 'missing code') {
        $callbackQuery['code'] = $outcome === 'exchange failure' ? 'invalid-code' : 'oauth-code-1';
    }

    $response = $this->get('/mailbox/oauth/'.$provider.'/callback?'.http_build_query($callbackQuery));
    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $result);

    expect($location)->toStartWith($expectedUrl.(str_contains($expectedUrl, '?') ? '&' : '?'));
    expect($result['mailbox_oauth'])->toBe($outcome === 'success' ? 'success' : 'error');
    expect($result['user_reference'])->toBe('web-user-1');

    if ($outcome === 'success') {
        expect($result['mailbox_oauth_token_id'])->toBeString()->not->toBe('');
    } else {
        expect($result['mailbox_oauth_error'])->toBe(match ($outcome) {
            'cancelled' => 'access_denied',
            'exchange failure' => 'oauth_callback_failed',
            'missing code' => 'missing_code_or_state',
        });
    }
})->with('return providers')->with('return outcomes')->with('return destinations');

it('revalidates stored returns and falls back to existing JSON results when no destination remains trusted', function (string $provider, string $outcome): void {
    config()->set('mailbox.oauth.default_return_url', '//attacker.example/collect');
    $authorization = $this->get('/mailbox/oauth/'.$provider.'/redirect?'.http_build_query([
        'return_to' => 'https://app.example.com/settings/mail',
        'user_reference' => 'web-user-1',
    ]));
    $authorization->assertRedirect();
    parse_str((string) parse_url((string) $authorization->headers->get('Location'), PHP_URL_QUERY), $authorizationQuery);

    config()->set('mailbox.oauth.allowed_return_hosts', []);
    $callbackQuery = ['state' => $authorizationQuery['state']];

    if ($outcome === 'cancelled') {
        $callbackQuery['error'] = 'access_denied';
        $callbackQuery['error_description'] = 'User cancelled';
    } elseif ($outcome !== 'missing code') {
        $callbackQuery['code'] = $outcome === 'exchange failure' ? 'invalid-code' : 'oauth-code-1';
    }

    $response = $this->get('/mailbox/oauth/'.$provider.'/callback?'.http_build_query($callbackQuery));
    $response->assertHeaderMissing('Location');

    if ($outcome === 'success') {
        $response->assertOk()->assertJson([
            'success' => true,
            'provider' => $provider === 'gmail' ? 'gmail-user' : 'ms-graph-user',
            'email' => 'user@example.com',
            'external_user_id' => $provider === 'gmail' ? 'google-user-1' : 'microsoft-user-1',
        ])->assertJsonStructure(['token_id', 'expires_at']);
    } else {
        $response->assertStatus($outcome === 'missing code' ? 422 : 400)->assertJson([
            'success' => false,
            'error' => match ($outcome) {
                'cancelled' => 'access_denied',
                'exchange failure' => 'oauth_callback_failed',
                'missing code' => 'missing_code_or_state',
            },
        ])->assertJsonStructure(['error_description']);
    }
})->with('return providers')->with('return outcomes');
