<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Pyle\Mailbox\Events\TokenAcquired;
use Pyle\Mailbox\Events\TokenRefreshFailed;
use Pyle\Mailbox\Exceptions\AuthenticationException;

class TokenManager
{
    private const CACHE_TTL_FALLBACK = 300;

    /** @var array<string, mixed> */
    private array $metadata = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?Client $client = null,
    ) {}

    public function getToken(bool $forceRefresh = false): string
    {
        $cacheKey = $this->cacheKey();

        if (! $forceRefresh) {
            $cached = Cache::store($this->cacheStore())->get($cacheKey);
            if (is_array($cached) && isset($cached['access_token']) && is_string($cached['access_token'])) {
                $this->metadata = $cached;

                return $cached['access_token'];
            }
        }

        return $this->fetchAndCacheToken($cacheKey);
    }

    public function invalidateToken(): void
    {
        Cache::store($this->cacheStore())->forget($this->cacheKey());
        $this->metadata = [];
    }

    public function tokenExpiresIn(): ?int
    {
        $expiresAt = $this->metadata['expires_at'] ?? null;

        if (! is_int($expiresAt)) {
            return null;
        }

        return max(0, $expiresAt - time());
    }

    public function tokenExpiresAt(): ?CarbonImmutable
    {
        $expiresAt = $this->metadata['expires_at'] ?? null;

        return is_int($expiresAt) ? CarbonImmutable::createFromTimestampUTC($expiresAt) : null;
    }

    public function secretExpiresAt(): ?CarbonImmutable
    {
        $token = $this->metadata['access_token'] ?? null;

        if (! is_string($token) || ! str_contains($token, '.')) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return null;
        }

        $normalized = strtr($parts[1], '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $payload = json_decode((string) base64_decode($normalized, true), true);

        if (! is_array($payload) || ! isset($payload['xms_ssm']) || ! is_numeric($payload['xms_ssm'])) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $payload['xms_ssm']);
    }

    private function fetchAndCacheToken(string $cacheKey): string
    {
        $tenantId = (string) ($this->config['tenant_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new AuthenticationException(
                'Failed to authenticate with Microsoft Graph. Verify that MS365_TENANT_ID, MS365_CLIENT_ID, and MS365_CLIENT_SECRET are set.',
                'Check config/mailbox.php and your environment variables.',
            );
        }

        $client = $this->client ?? new Client([
            'base_uri' => sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/', $tenantId),
            'timeout' => (int) ($this->config['timeout'] ?? 30),
        ]);

        try {
            $response = $client->post('token', [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Event::dispatch(new TokenRefreshFailed(
                driver: 'ms-graph',
                error: $e->getMessage(),
                guidance: 'Rotate secret in Entra ID if expired and update MS365_CLIENT_SECRET.',
            ));

            throw new AuthenticationException(
                'Failed to authenticate with Microsoft Graph. The client secret may have expired.',
                'Rotate secret in Entra ID and update MS365_CLIENT_SECRET.',
                previous: $e,
            );
        }

        $accessToken = (string) ($payload['access_token'] ?? '');
        $expiresIn = max(60, (int) ($payload['expires_in'] ?? 3600));
        $buffer = (int) ($this->config['token_refresh_buffer'] ?? config('mailbox.token_refresh_buffer', 300));
        $ttl = max(self::CACHE_TTL_FALLBACK, $expiresIn - $buffer);

        $this->metadata = [
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
            'expires_at' => time() + $expiresIn,
        ];

        Cache::store($this->cacheStore())->put($cacheKey, $this->metadata, $ttl);

        Event::dispatch(new TokenAcquired(driver: 'ms-graph', expiresIn: $expiresIn));

        return $accessToken;
    }

    private function cacheStore(): ?string
    {
        $store = $this->config['cache_store'] ?? config('mailbox.cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private function cacheKey(): string
    {
        $prefix = (string) ($this->config['cache_prefix'] ?? config('mailbox.cache_prefix', 'mailbox_token'));
        $tenant = (string) ($this->config['tenant_id'] ?? 'tenant');
        $client = (string) ($this->config['client_id'] ?? 'client');

        return sprintf('%s:%s:%s', $prefix, $tenant, $client);
    }
}
