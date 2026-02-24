<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsRawClient;
use Pyle\Mailbox\Events\ConnectionTestCompleted;
use Pyle\Mailbox\Events\SecretExpirationWarning;

class MsGraphDriver implements MailboxDriver, SupportsRawClient
{
    private TokenManager $tokenManager;

    private RateLimiter $rateLimiter;

    private GraphClient $client;

    private BatchRequest $batch;

    private MsGraphDeltaSync $deltaSync;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {
        $this->tokenManager = new TokenManager(array_merge((array) config('mailbox'), $config));
        $this->rateLimiter = new RateLimiter(array_merge((array) config('mailbox'), $config));
        $this->client = new GraphClient(array_merge((array) config('mailbox'), $config), $this->tokenManager, $this->rateLimiter);
        $this->batch = new BatchRequest($this->client);
        $this->deltaSync = new MsGraphDeltaSync($this->client);
    }

    public function mailbox(string $emailAddress): MailboxResource
    {
        return new MsGraphMailboxResource($this->client, $this->batch, $this->deltaSync, $emailAddress);
    }

    public function testConnection(?string $emailAddress = null): ConnectionTestResult
    {
        $start = microtime(true);

        try {
            $this->tokenManager->getToken();

            if ($emailAddress !== null) {
                $this->client->get(sprintf('users/%s/mailFolders/inbox', rawurlencode($emailAddress)), mailbox: $emailAddress);
            } else {
                $this->client->get('users?$top=1');
            }

            $latency = (int) round((microtime(true) - $start) * 1000);

            Event::dispatch(new ConnectionTestCompleted('ms-graph', $emailAddress, true, $latency));

            return new ConnectionTestResult(
                success: true,
                error: null,
                latencyMs: $latency,
                authenticatedAs: (string) ($this->config['client_id'] ?? null),
                accessibleMailboxes: $emailAddress ? [$emailAddress] : [],
            );
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            Event::dispatch(new ConnectionTestCompleted('ms-graph', $emailAddress, false, $latency));

            return new ConnectionTestResult(
                success: false,
                error: $e->getMessage(),
                latencyMs: $latency,
                authenticatedAs: null,
                accessibleMailboxes: [],
            );
        }
    }

    public function healthCheck(): HealthCheckResult
    {
        $tokenValid = false;
        $apiReachable = false;
        $latency = null;

        try {
            $start = microtime(true);
            $this->tokenManager->getToken();
            $tokenValid = true;
            $this->client->get('users?$top=1');
            $apiReachable = true;
            $latency = (int) round((microtime(true) - $start) * 1000);
        } catch (\Throwable) {
            // best effort
        }

        $secretExpiresAt = $this->tokenManager->secretExpiresAt();
        $warningDays = (int) config('mailbox.secret_expiry_warning_days', 30);
        $warning = $secretExpiresAt instanceof CarbonImmutable
            ? CarbonImmutable::now()->diffInDays($secretExpiresAt, false) <= $warningDays
            : false;

        if ($warning) {
            /** @var CarbonImmutable $secretExpiresAt */
            Event::dispatch(new SecretExpirationWarning(
                driver: 'ms-graph',
                expiresAt: $secretExpiresAt,
                daysRemaining: (int) CarbonImmutable::now()->diffInDays($secretExpiresAt, false),
            ));
        }

        return new HealthCheckResult(
            healthy: $tokenValid && $apiReachable,
            tokenValid: $tokenValid,
            tokenExpiresIn: $this->tokenManager->tokenExpiresIn(),
            apiReachable: $apiReachable,
            latencyMs: $latency,
            secretExpiresAt: $secretExpiresAt,
            secretExpirationWarning: $warning,
        );
    }

    public function raw(): GraphClient
    {
        return $this->client;
    }
}
