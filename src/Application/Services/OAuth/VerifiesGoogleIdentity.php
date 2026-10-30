<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use SensitiveParameter;

trait VerifiesGoogleIdentity
{
    /** @return array{sub: string, nonce?: mixed, email?: mixed, name?: mixed, hd?: mixed}|null */
    private function googleIdentityClaims(#[SensitiveParameter] mixed $jwt, string $clientId): ?array
    {
        // Only direct, TLS-verified, client-authenticated token responses enter this decoder (OIDC Core 3.1.3.7).
        $parts = is_string($jwt) && preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $jwt) === 1 ? explode('.', $jwt) : [];
        $header = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true) : null;
        $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true) : null;
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256'
            || ! is_array($claims) || ! in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ($claims['aud'] ?? null) !== $clientId || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || ! is_string($claims['sub'] ?? null) || preg_match('/\A[\x21-\x7e]{1,255}\z/', $claims['sub']) !== 1
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= now()->getTimestamp()
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > now()->getTimestamp() + 60) {
            return null;
        }

        return $claims;
    }
}
