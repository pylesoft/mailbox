<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Pyle\Mailbox\Http\Controllers\GmailOAuthController;
use Pyle\Mailbox\Http\Controllers\MsGraphOAuthController;
use Pyle\Mailbox\Models\MailboxOAuthToken;
use Pyle\Mailbox\Services\OAuth\GmailOAuthCallbackResult;
use Pyle\Mailbox\Services\OAuth\GmailUserOAuthService;
use Pyle\Mailbox\Services\OAuth\MsGraphOAuthCallbackResult;
use Pyle\Mailbox\Services\OAuth\MsGraphUserOAuthService;

dataset('oauth controllers', [
    'gmail' => [
        fn (GmailOAuthServiceFake $service): GmailOAuthController => new GmailOAuthController($service),
        fn (string $authorizationUrl, ?GmailOAuthCallbackResult $result = null, ?Throwable $failure = null, ?array $stateContext = null): GmailOAuthServiceFake => new GmailOAuthServiceFake($authorizationUrl, $result, $failure, $stateContext),
        fn (MailboxOAuthToken $token, ?string $returnTo, ?string $userReference): GmailOAuthCallbackResult => new GmailOAuthCallbackResult($token, $returnTo, $userReference),
    ],
    'ms graph' => [
        fn (MsGraphOAuthServiceFake $service): MsGraphOAuthController => new MsGraphOAuthController($service),
        fn (string $authorizationUrl, ?MsGraphOAuthCallbackResult $result = null, ?Throwable $failure = null, ?array $stateContext = null): MsGraphOAuthServiceFake => new MsGraphOAuthServiceFake($authorizationUrl, $result, $failure, $stateContext),
        fn (MailboxOAuthToken $token, ?string $returnTo, ?string $userReference): MsGraphOAuthCallbackResult => new MsGraphOAuthCallbackResult($token, $returnTo, $userReference),
    ],
]);

it('builds oauth redirects from normalized request input', function (
    Closure $makeController,
    Closure $makeService,
    Closure $makeResult,
): void {
    unset($makeResult);
    $service = $makeService('https://oauth.example.test/authorize');

    $controller = $makeController($service);
    $response = $controller->redirect(Request::create('/oauth/redirect', 'GET', [
        'mailbox_connection_id' => '42',
        'return_to' => '/dashboard',
        'user_reference' => 'user-1',
    ]));
    $location = $response->getTargetUrl();
    $parts = parse_url($location);

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($location)->toBe('https://oauth.example.test/authorize');
    expect($parts['scheme'] ?? null)->toBe('https');
    expect($parts['host'] ?? null)->toBe('oauth.example.test');
    expect($parts['path'] ?? null)->toBe('/authorize');
    expect($service->authorizationCalls)->toBe([
        [
            'connection_id' => 42,
            'return_to' => '/dashboard',
            'user_reference' => 'user-1',
        ],
    ]);
})->with('oauth controllers');

it('returns validation json when callback is missing the code or state', function (
    Closure $makeController,
    Closure $makeService,
    Closure $makeResult,
): void {
    unset($makeResult);
    $service = $makeService('https://oauth.example.test/authorize');

    $controller = $makeController($service);
    $response = $controller->callback(Request::create('/oauth/callback', 'GET'));
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(422);
    expect($payload)->toMatchArray([
        'success' => false,
        'error' => 'missing_code_or_state',
        'error_description' => null,
    ]);
    expect($payload['success'])->toBeFalse();
    expect($payload['error'])->toBe('missing_code_or_state');
    expect($service->callbackCalls)->toBe([]);
    expect($service->stateContextCalls)->toBe([]);
})->with('oauth controllers');

it('redirects successful callbacks back to the caller with token context', function (
    Closure $makeController,
    Closure $makeService,
    Closure $makeResult,
): void {
    $token = MailboxOAuthToken::make([
        'provider' => 'gmail-user',
        'email' => 'user@example.com',
        'external_user_id' => 'external-1',
        'access_token' => 'token',
        'token_type' => 'Bearer',
    ]);
    $token->id = 99;

    $service = $makeService(
        'https://oauth.example.test/authorize',
        $makeResult($token, '/done', 'user-1'),
    );

    $controller = $makeController($service);
    $response = $controller->callback(Request::create('/oauth/callback', 'GET', [
        'state' => 'state-1',
        'code' => 'code-1',
    ]));
    $location = $response->getTargetUrl();
    $parts = parse_url($location);
    parse_str($parts['query'] ?? '', $query);

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($parts['path'] ?? null)->toBe('/done');
    expect($query['mailbox_oauth'] ?? null)->toBe('success');
    expect($query['mailbox_oauth_token_id'] ?? null)->toBe('99');
    expect($query['user_reference'] ?? null)->toBe('user-1');
    expect($service->callbackCalls)->toBe([
        [
            'state' => 'state-1',
            'code' => 'code-1',
        ],
    ]);
    expect($service->stateContextCalls)->toBe([]);
})->with('oauth controllers');

it('returns provider callback failures as json when no return url can be recovered', function (
    Closure $makeController,
    Closure $makeService,
    Closure $makeResult,
): void {
    unset($makeResult);
    $service = $makeService(
        'https://oauth.example.test/authorize',
        null,
        new RuntimeException('The callback token exchange failed.'),
        [],
    );

    $controller = $makeController($service);
    $response = $controller->callback(Request::create('/oauth/callback', 'GET', [
        'state' => 'state-1',
        'code' => 'code-1',
    ]));
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(400);
    expect($payload)->toMatchArray([
        'success' => false,
        'error' => 'oauth_callback_failed',
        'error_description' => 'The callback token exchange failed.',
    ]);
    expect($payload['success'])->toBeFalse();
    expect($payload['error_description'])->toBe('The callback token exchange failed.');
    expect($service->callbackCalls)->toBe([
        [
            'state' => 'state-1',
            'code' => 'code-1',
        ],
    ]);
    expect($service->stateContextCalls)->toBe(['state-1']);
})->with('oauth controllers');

final class GmailOAuthServiceFake extends GmailUserOAuthService
{
    /** @var array<int, array{connection_id:?int, return_to:?string, user_reference:?string}> */
    public array $authorizationCalls = [];

    /** @var array<int, array{state:string, code:string}> */
    public array $callbackCalls = [];

    /** @var array<int, string> */
    public array $stateContextCalls = [];

    public function __construct(
        private readonly string $authorizationUrl,
        private readonly ?GmailOAuthCallbackResult $callbackResult = null,
        private readonly ?Throwable $callbackFailure = null,
        private readonly ?array $context = null,
    ) {}

    public function authorizationUrl(?int $connectionId = null, ?string $returnTo = null, ?string $userReference = null): string
    {
        $this->authorizationCalls[] = [
            'connection_id' => $connectionId,
            'return_to' => $returnTo,
            'user_reference' => $userReference,
        ];

        return $this->authorizationUrl;
    }

    public function handleCallback(string $state, string $code): GmailOAuthCallbackResult
    {
        $this->callbackCalls[] = ['state' => $state, 'code' => $code];

        if ($this->callbackFailure instanceof Throwable) {
            throw $this->callbackFailure;
        }

        if ($this->callbackResult instanceof GmailOAuthCallbackResult) {
            return $this->callbackResult;
        }

        throw new RuntimeException('No Gmail callback result configured for the test.');
    }

    public function stateContext(string $state): ?array
    {
        $this->stateContextCalls[] = $state;

        return $this->context;
    }
}

final class MsGraphOAuthServiceFake extends MsGraphUserOAuthService
{
    /** @var array<int, array{connection_id:?int, return_to:?string, user_reference:?string}> */
    public array $authorizationCalls = [];

    /** @var array<int, array{state:string, code:string}> */
    public array $callbackCalls = [];

    /** @var array<int, string> */
    public array $stateContextCalls = [];

    public function __construct(
        private readonly string $authorizationUrl,
        private readonly ?MsGraphOAuthCallbackResult $callbackResult = null,
        private readonly ?Throwable $callbackFailure = null,
        private readonly ?array $context = null,
    ) {}

    public function authorizationUrl(?int $connectionId = null, ?string $returnTo = null, ?string $userReference = null): string
    {
        $this->authorizationCalls[] = [
            'connection_id' => $connectionId,
            'return_to' => $returnTo,
            'user_reference' => $userReference,
        ];

        return $this->authorizationUrl;
    }

    public function handleCallback(string $state, string $code): MsGraphOAuthCallbackResult
    {
        $this->callbackCalls[] = ['state' => $state, 'code' => $code];

        if ($this->callbackFailure instanceof Throwable) {
            throw $this->callbackFailure;
        }

        if ($this->callbackResult instanceof MsGraphOAuthCallbackResult) {
            return $this->callbackResult;
        }

        throw new RuntimeException('No MS Graph callback result configured for the test.');
    }

    public function stateContext(string $state): ?array
    {
        $this->stateContextCalls[] = $state;

        return $this->context;
    }
}
