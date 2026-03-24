<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Facades\Event;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Drivers\Gmail\Contracts\SupportsRawClient;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\Events\ConnectionTestCompleted;

class GmailDriver implements MailboxDriver, SupportsRawClient
{
    private GmailTokenManager $tokenManager;

    private RateLimiter $rateLimiter;

    private GmailClient $client;

    private GmailDeltaSync $deltaSync;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {
        $merged = array_merge((array) config('mailbox'), $config);

        $this->tokenManager = new GmailTokenManager($merged);
        $this->rateLimiter = new RateLimiter($merged);
        $this->client = new GmailClient($merged, $this->tokenManager, $this->rateLimiter);
        $this->deltaSync = new GmailDeltaSync($this->client);
    }

    public function mailbox(string $emailAddress): MailboxResource
    {
        return new GmailMailboxResource($this->client, $this->deltaSync, $emailAddress);
    }

    public function testConnection(?string $emailAddress = null): ConnectionTestResult
    {
        $start = microtime(true);
        $mailbox = $this->resolveProbeMailbox($emailAddress);

        if (! is_string($mailbox)) {
            throw new \RuntimeException('A probe mailbox is required for Gmail connection tests.');
        }

        try {
            $this->tokenManager->getToken($mailbox);
            $this->client->get(sprintf('users/%s/labels/INBOX', rawurlencode($mailbox)), mailbox: $mailbox);

            $latency = (int) round((microtime(true) - $start) * 1000);

            Event::dispatch(new ConnectionTestCompleted('gmail', $mailbox, true, $latency));

            return new ConnectionTestResult(
                success: true,
                error: null,
                latencyMs: $latency,
                authenticatedAs: (string) ($this->config['service_account_json_path'] ?? $this->config['service_account_json'] ?? null),
                accessibleMailboxes: [(string) $mailbox],
            );
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            Event::dispatch(new ConnectionTestCompleted('gmail', $mailbox, false, $latency));

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
        $mailbox = $this->resolveProbeMailbox(null, false);
        $tokenValid = false;
        $apiReachable = false;
        $latency = null;

        if ($mailbox !== null) {
            try {
                $start = microtime(true);
                $this->tokenManager->getToken($mailbox);
                $tokenValid = true;
                $this->client->get(sprintf('users/%s/profile', rawurlencode($mailbox)), mailbox: $mailbox);
                $apiReachable = true;
                $latency = (int) round((microtime(true) - $start) * 1000);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return new HealthCheckResult(
            healthy: $tokenValid && $apiReachable,
            tokenValid: $tokenValid,
            tokenExpiresIn: $mailbox !== null ? $this->tokenManager->tokenExpiresIn($mailbox) : null,
            apiReachable: $apiReachable,
            latencyMs: $latency,
            secretExpiresAt: null,
            secretExpirationWarning: false,
        );
    }

    public function raw(): GmailClient
    {
        return $this->client;
    }

    private function resolveProbeMailbox(?string $emailAddress, bool $throwIfMissing = true): ?string
    {
        $candidate = is_string($emailAddress) ? trim($emailAddress) : '';

        if ($candidate !== '') {
            return strtolower($candidate);
        }

        $configured = (string) ($this->config['subject_email'] ?? '');

        if ($configured !== '') {
            return strtolower(trim($configured));
        }

        if ($throwIfMissing) {
            throw new \RuntimeException('A mailbox email address is required for Gmail driver probes. Provide an email or configure mailbox.drivers.gmail.subject_email.');
        }

        return null;
    }
}
