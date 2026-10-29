<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Pyle\Mailbox\Exceptions\OAuthEnrollmentException;
use SensitiveParameter;

trait VerifiesMsGraphIdentity
{
    private function microsoftAuthority(): string
    {
        $authority = config('mailbox.drivers.ms-graph.tenant_id');
        if (! is_string($authority) || (! in_array(strtolower($authority), ['common', 'organizations', 'consumers'], true)
            && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $authority) !== 1)) {
            throw new OAuthEnrollmentException('Microsoft OAuth requires an explicit supported authority or tenant GUID.');
        }

        return strtolower($authority);
    }

    private function acceptsMicrosoftIdentity(string $authority, string $issuer, string $tenant): bool
    {
        if (preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $tenant) !== 1
            || $issuer !== 'https://login.microsoftonline.com/'.$tenant.'/v2.0') {
            return false;
        }
        $personal = strtolower($tenant) === '9188040d-6c67-4c5b-b112-36a304b66dad';

        return match ($authority) {
            'common' => true,
            'organizations' => ! $personal,
            'consumers' => $personal,
            default => strtolower($tenant) === $authority,
        };
    }

    /** @return array{iss: string, tid: string, sub: string, oid: string, nonce?: mixed} */
    private function microsoftIdentityClaims(#[SensitiveParameter] mixed $jwt, string $clientId, string $authority): array
    {
        // Only direct, TLS-verified, client-authenticated token responses enter this decoder (OIDC Core 3.1.3.7).
        $parts = is_string($jwt) && preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $jwt) === 1 ? explode('.', $jwt) : [];
        $header = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true) : null;
        $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true) : null;
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_array($claims)
            || ! is_string($claims['iss'] ?? null) || ! is_string($claims['tid'] ?? null)
            || ! $this->acceptsMicrosoftIdentity($authority, $claims['iss'], $claims['tid'])
            || ($claims['ver'] ?? null) !== '2.0' || ($claims['aud'] ?? null) !== $clientId
            || (array_key_exists('azp', $claims) && $claims['azp'] !== $clientId)
            || ! is_string($claims['sub'] ?? null) || preg_match('/\A[\x21-\x7e]{1,255}\z/', $claims['sub']) !== 1
            || ! is_string($claims['oid'] ?? null) || preg_match('/\A[\x21-\x7e]{1,255}\z/', $claims['oid']) !== 1
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= now()->getTimestamp()
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > now()->getTimestamp() + 60
            || ! is_int($claims['nbf'] ?? null) || $claims['nbf'] > now()->getTimestamp() + 60) {
            throw new OAuthEnrollmentException('Microsoft authorization identity could not be verified.');
        }

        return $claims;
    }

    private function token(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' && preg_match('/[\x00-\x20\x7f]/', $value) !== 1 ? $value : null;
    }
}
