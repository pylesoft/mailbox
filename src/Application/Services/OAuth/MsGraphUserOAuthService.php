<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Illuminate\Support\Facades\Cache;
use Pyle\Mailbox\Exceptions\OAuthEnrollmentException;
use SensitiveParameter;

class MsGraphUserOAuthService
{
    use NormalizesReturnTo;

    public function authorizationUrl(?int $connectionId = null, ?string $returnTo = null, ?string $userReference = null): string
    {
        throw new OAuthEnrollmentException('Reconnect using MsGraphGrantOAuthService with an explicitly authorized host context and selected grant. Legacy OAuth ownership cannot be verified.');
    }

    public function handleCallback(#[SensitiveParameter] string $state, #[SensitiveParameter] string $code): MsGraphOAuthCallbackResult
    {
        throw new OAuthEnrollmentException('Reconnect using MsGraphGrantOAuthService with an explicitly authorized host context and selected grant. Legacy OAuth ownership cannot be verified.');
    }

    /**
     * @return array{return_to: ?string, user_reference: ?string}|null
     */
    public function stateContext(#[SensitiveParameter] string $state): ?array
    {
        $payload = Cache::get($this->stateKey($state));

        if (! is_array($payload)) {
            return null;
        }

        return $this->stateContextFromPayload($payload);
    }

    public function stateKey(#[SensitiveParameter] string $state): string
    {
        return 'mailbox:oauth:state:'.$state;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{return_to: ?string, user_reference: ?string}
     */
    private function stateContextFromPayload(array $payload): array
    {
        return [
            'return_to' => $this->normalizeReturnTo($payload['return_to'] ?? null) ?? $this->defaultReturnTo(),
            'user_reference' => $this->stringOrNull($payload['user_reference'] ?? null),
        ];
    }

    private function defaultReturnTo(): ?string
    {
        $value = config('mailbox.oauth.default_return_url');

        return $this->normalizeReturnTo(is_string($value) ? $value : null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
