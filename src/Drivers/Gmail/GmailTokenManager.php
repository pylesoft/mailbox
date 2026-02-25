<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pyle\Mailbox\Events\TokenAcquired;
use Pyle\Mailbox\Events\TokenRefreshFailed;
use Pyle\Mailbox\Exceptions\AuthenticationException;

class GmailTokenManager
{
    private const CACHE_TTL_FALLBACK = 300;

    /** @var array<string, array<string, mixed>> */
    private array $metadataByMailbox = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?Client $client = null,
    ) {}

    public function getToken(string $mailbox, bool $forceRefresh = false): string
    {
        $normalizedMailbox = $this->normalizeMailbox($mailbox);
        $cacheKey = $this->cacheKey($normalizedMailbox);

        if (! $forceRefresh) {
            $cached = Cache::store($this->cacheStore())->get($cacheKey);
            if (is_array($cached) && isset($cached['access_token']) && is_string($cached['access_token'])) {
                $this->metadataByMailbox[$normalizedMailbox] = $cached;

                $this->logDebug('Gmail token cache hit', [
                    'cache_key' => $cacheKey,
                    'mailbox' => $normalizedMailbox,
                    'expires_in' => $this->tokenExpiresIn($normalizedMailbox),
                ]);

                return $cached['access_token'];
            }

            $this->logDebug('Gmail token cache miss', [
                'cache_key' => $cacheKey,
                'mailbox' => $normalizedMailbox,
            ]);
        } else {
            $this->logDebug('Gmail token refresh forced', [
                'cache_key' => $cacheKey,
                'mailbox' => $normalizedMailbox,
            ]);
        }

        return $this->fetchAndCacheToken($normalizedMailbox, $cacheKey);
    }

    public function invalidateToken(?string $mailbox = null): void
    {
        if ($mailbox !== null) {
            $normalizedMailbox = $this->normalizeMailbox($mailbox);
            Cache::store($this->cacheStore())->forget($this->cacheKey($normalizedMailbox));
            unset($this->metadataByMailbox[$normalizedMailbox]);

            $this->logInfo('Gmail token cache invalidated', [
                'mailbox' => $normalizedMailbox,
            ]);

            return;
        }

        foreach (array_keys($this->metadataByMailbox) as $trackedMailbox) {
            Cache::store($this->cacheStore())->forget($this->cacheKey($trackedMailbox));
        }

        $this->metadataByMailbox = [];

        $this->logInfo('Gmail token cache invalidated for all tracked mailboxes');
    }

    public function tokenExpiresIn(?string $mailbox = null): ?int
    {
        $metadata = $this->metadata($mailbox);
        $expiresAt = $metadata['expires_at'] ?? null;

        if (! is_int($expiresAt)) {
            return null;
        }

        return max(0, $expiresAt - time());
    }

    public function tokenExpiresAt(?string $mailbox = null): ?CarbonImmutable
    {
        $metadata = $this->metadata($mailbox);
        $expiresAt = $metadata['expires_at'] ?? null;

        return is_int($expiresAt) ? CarbonImmutable::createFromTimestampUTC($expiresAt) : null;
    }

    public function secretExpiresAt(): ?CarbonImmutable
    {
        return null;
    }

    private function fetchAndCacheToken(string $mailbox, string $cacheKey): string
    {
        $serviceAccount = $this->serviceAccountConfig();
        $clientEmail = (string) ($serviceAccount['client_email'] ?? '');
        $privateKey = (string) ($serviceAccount['private_key'] ?? '');
        $tokenUri = (string) ($this->config['token_uri'] ?? $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token');

        if ($clientEmail === '' || $privateKey === '') {
            throw new AuthenticationException(
                'Failed to authenticate with Gmail. Verify GMAIL_SERVICE_ACCOUNT_JSON or GMAIL_SERVICE_ACCOUNT_JSON_PATH.',
                'Ensure service account credentials include client_email and private_key.',
            );
        }

        $assertion = $this->createAssertion($clientEmail, $privateKey, $tokenUri, $mailbox);

        $client = $this->client ?? new Client([
            'base_uri' => rtrim($tokenUri, '/').'/'.(str_ends_with($tokenUri, '/') ? '' : ''),
            'timeout' => (int) ($this->config['timeout'] ?? 30),
        ]);

        try {
            $response = $client->post($tokenUri, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Event::dispatch(new TokenRefreshFailed(
                driver: 'gmail',
                error: $e->getMessage(),
                guidance: 'Verify service account domain-wide delegation and token endpoint access.',
            ));

            $this->logInfo('Gmail token refresh failed', [
                'mailbox' => $mailbox,
                'error' => $e->getMessage(),
            ]);

            throw new AuthenticationException(
                'Failed to authenticate with Gmail using service account delegation.',
                'Verify service account key, delegation, scopes, and impersonated mailbox permissions.',
                previous: $e,
            );
        }

        $accessToken = (string) ($payload['access_token'] ?? '');

        if ($accessToken === '') {
            throw new AuthenticationException(
                'Failed to authenticate with Gmail. Token response did not include access_token.',
                'Verify OAuth scopes and service account delegation setup.',
            );
        }

        $expiresIn = max(60, (int) ($payload['expires_in'] ?? 3600));
        $buffer = (int) ($this->config['token_refresh_buffer'] ?? config('mailbox.token_refresh_buffer', 300));
        $ttl = max(self::CACHE_TTL_FALLBACK, $expiresIn - $buffer);

        $metadata = [
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
            'expires_at' => time() + $expiresIn,
        ];

        $this->metadataByMailbox[$mailbox] = $metadata;

        Cache::store($this->cacheStore())->put($cacheKey, $metadata, $ttl);

        Event::dispatch(new TokenAcquired(driver: 'gmail', expiresIn: $expiresIn));

        $this->logInfo('Gmail token acquired', [
            'mailbox' => $mailbox,
            'expires_in' => $expiresIn,
            'cache_ttl' => $ttl,
        ]);

        return $accessToken;
    }

    private function createAssertion(string $clientEmail, string $privateKey, string $tokenUri, string $mailbox): string
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + 3600;
        $scopes = $this->scopes();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $clientEmail,
            'scope' => implode(' ', $scopes),
            'aud' => $tokenUri,
            'exp' => $expiresAt,
            'iat' => $issuedAt,
            'sub' => $mailbox,
        ];

        $encodedHeader = $this->base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = $this->base64UrlEncode((string) json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader.'.'.$encodedClaims;

        $signature = '';
        $result = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if ($result !== true) {
            throw new AuthenticationException(
                'Failed to sign Gmail service account assertion.',
                'Ensure the private key is valid and OpenSSL can sign with RS256.',
            );
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /** @return array<string, mixed> */
    private function serviceAccountConfig(): array
    {
        $path = $this->config['service_account_json_path'] ?? null;

        if (is_string($path) && trim($path) !== '') {
            $contents = @file_get_contents($path);
            if (is_string($contents) && trim($contents) !== '') {
                $decoded = json_decode($contents, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $configured = $this->config['service_account_json'] ?? null;

        if (! is_string($configured) || trim($configured) === '') {
            return [];
        }

        if (str_starts_with(trim($configured), '{')) {
            $decoded = json_decode($configured, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (is_file($configured)) {
            $contents = @file_get_contents($configured);
            if (! is_string($contents) || trim($contents) === '') {
                return [];
            }

            $decoded = json_decode($contents, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @return array<int, string> */
    private function scopes(): array
    {
        $configuredScopes = $this->config['scopes'] ?? ['https://www.googleapis.com/auth/gmail.modify'];

        if (! is_array($configuredScopes)) {
            return ['https://www.googleapis.com/auth/gmail.modify'];
        }

        $scopes = array_values(array_filter(array_map(static fn (mixed $scope): string => is_string($scope) ? trim($scope) : '', $configuredScopes), static fn (string $scope): bool => $scope !== ''));

        return $scopes !== [] ? $scopes : ['https://www.googleapis.com/auth/gmail.modify'];
    }

    private function cacheStore(): ?string
    {
        $store = $this->config['cache_store'] ?? config('mailbox.cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private function cacheKey(string $mailbox): string
    {
        $prefix = (string) ($this->config['cache_prefix'] ?? config('mailbox.cache_prefix', 'mailbox_token'));
        $clientEmail = (string) ($this->serviceAccountConfig()['client_email'] ?? 'client');

        return sprintf('%s:gmail:%s:%s', $prefix, sha1(strtolower($clientEmail)), sha1($mailbox));
    }

    /** @return array<string, mixed> */
    private function metadata(?string $mailbox): array
    {
        if (is_string($mailbox) && $mailbox !== '') {
            return $this->metadataByMailbox[$this->normalizeMailbox($mailbox)] ?? [];
        }

        return $this->metadataByMailbox[array_key_first($this->metadataByMailbox)] ?? [];
    }

    private function normalizeMailbox(string $mailbox): string
    {
        return strtolower(trim($mailbox));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $context */
    private function logDebug(string $message, array $context = []): void
    {
        Log::channel((string) config('mailbox.log_channel', 'stack'))->debug($message, $context);
    }

    /** @param array<string, mixed> $context */
    private function logInfo(string $message, array $context = []): void
    {
        Log::channel((string) config('mailbox.log_channel', 'stack'))->info($message, $context);
    }
}
