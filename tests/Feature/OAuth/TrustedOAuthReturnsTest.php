<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\MailboxServiceProvider;
use Pyle\Mailbox\Services\OAuth\GmailUserOAuthService;
use Pyle\Mailbox\Services\OAuth\MsGraphUserOAuthService;

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
});

dataset('return providers', ['gmail', 'ms-graph']);
dataset('return outcomes', ['legacy callback', 'cancelled', 'missing code']);
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

it('returns historical OAuth errors only to permitted application destinations', function (string $provider, string $outcome, string $returnTo, string $expectedUrl): void {
    $service = app($provider === 'gmail' ? GmailUserOAuthService::class : MsGraphUserOAuthService::class);
    Cache::put($service->stateKey('historical-state'), [
        'return_to' => $returnTo, 'user_reference' => 'web-user-1',
    ], now()->addMinutes(5));
    $callbackQuery = ['state' => 'historical-state'];
    if ($outcome === 'cancelled') {
        $callbackQuery['error'] = 'access_denied';
        $callbackQuery['error_description'] = 'User cancelled';
    } elseif ($outcome === 'legacy callback') {
        $callbackQuery['code'] = 'private-code';
    }

    $response = $this->get('/mailbox/oauth/'.$provider.'/callback?'.http_build_query($callbackQuery));
    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $result);

    expect($location)->toStartWith($expectedUrl.(str_contains($expectedUrl, '?') ? '&' : '?'));
    expect($result['mailbox_oauth'])->toBe('error');
    expect($result['user_reference'])->toBe('web-user-1');
    expect($result['mailbox_oauth_error'])->toBe(match ($outcome) {
        'cancelled' => 'access_denied',
        'legacy callback' => 'oauth_callback_failed',
        'missing code' => 'missing_code_or_state',
    });
    if ($outcome === 'legacy callback') {
        expect($result['mailbox_oauth_error_description'])->toContain('Reconnect');
        expect($location)->not->toContain('private-code');
    }
    Http::assertNothingSent();
})->with('return providers')->with('return outcomes')->with('return destinations');

it('revalidates stored returns and keeps JSON errors when no destination remains trusted', function (string $provider, string $outcome): void {
    config()->set('mailbox.oauth.default_return_url', '//attacker.example/collect');
    $service = app($provider === 'gmail' ? GmailUserOAuthService::class : MsGraphUserOAuthService::class);
    Cache::put($service->stateKey('historical-state'), [
        'return_to' => 'https://app.example.com/settings/mail', 'user_reference' => 'web-user-1',
    ], now()->addMinutes(5));
    config()->set('mailbox.oauth.allowed_return_hosts', []);
    $callbackQuery = ['state' => 'historical-state'];
    if ($outcome === 'cancelled') {
        $callbackQuery['error'] = 'access_denied';
        $callbackQuery['error_description'] = 'User cancelled';
    } elseif ($outcome === 'legacy callback') {
        $callbackQuery['code'] = 'private-code';
    }

    $response = $this->get('/mailbox/oauth/'.$provider.'/callback?'.http_build_query($callbackQuery));

    $response->assertHeaderMissing('Location')->assertStatus($outcome === 'missing code' ? 422 : 400)->assertJson([
        'success' => false,
        'error' => match ($outcome) {
            'cancelled' => 'access_denied',
            'legacy callback' => 'oauth_callback_failed',
            'missing code' => 'missing_code_or_state',
        },
    ])->assertJsonStructure(['error_description']);
    if ($outcome === 'legacy callback') {
        expect($response->json('error_description'))->toContain('Reconnect');
        expect($response->getContent())->not->toContain('private-code');
    }
    Http::assertNothingSent();
})->with('return providers')->with('return outcomes');