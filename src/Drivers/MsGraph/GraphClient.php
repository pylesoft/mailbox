<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Event;
use Pyle\Mailbox\Drivers\Concerns\PerformsApiRequests;
use Pyle\Mailbox\Events\AccessDenied;
use Pyle\Mailbox\Events\ApiError;
use Pyle\Mailbox\Events\RateLimitHit;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;

class GraphClient
{
    use PerformsApiRequests;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly TokenManager $tokenManager,
        private readonly RateLimiter $rateLimiter,
        ?Client $client = null,
    ) {
        $this->bootApiClient(
            $this->config,
            $client,
            sprintf('https://graph.microsoft.com/%s/', $this->config['api_version'] ?? 'v1.0'),
        );
    }

    protected function driverKey(): string
    {
        return 'ms-graph';
    }

    protected function providerLabel(): string
    {
        return 'Graph';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildRequestOptions(array $options, string $mailboxKey): array
    {
        return array_merge($options, [
            'headers' => array_merge($this->defaultHeaders(), $options['headers'] ?? []),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->tokenManager->getToken(),
            'Accept' => 'application/json',
        ];

        if ((bool) ($this->config['prefer_immutable_ids'] ?? config('mailbox.prefer_immutable_ids', true))) {
            $headers['Prefer'] = 'IdType="ImmutableId"';
        }

        return $headers;
    }

    protected function mailboxKey(?string $mailbox): string
    {
        return $mailbox ?? 'global';
    }

    protected function shouldRetryRequest(
        RequestException $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        string $mailboxKey,
        int $attempt,
        int $durationMs,
        bool &$reauthAttempted,
    ): bool {
        $status = $e->getResponse()?->getStatusCode();

        if ($status === 401 && $this->retryUnauthorizedRequest($method, $endpoint, $mailbox, $attempt, $durationMs, $reauthAttempted)) {
            return true;
        }

        if ($status === 429 && $attempt <= $this->maxRetries) {
            return $this->retryRateLimitedRequest($e, $method, $endpoint, $mailbox, $attempt, $durationMs);
        }

        if ($status !== null && $status >= 500 && $attempt <= $this->maxRetries) {
            return $this->retryServerErrorRequest($status, $method, $endpoint, $mailbox, $attempt, $durationMs);
        }

        $this->logRequestFailureWithoutRetry($method, $endpoint, $mailbox, $attempt, $status, $durationMs);

        return false;
    }

    private function retryUnauthorizedRequest(
        string $method,
        string $endpoint,
        ?string $mailbox,
        int $attempt,
        int $durationMs,
        bool &$reauthAttempted,
    ): bool {
        if ($reauthAttempted) {
            return false;
        }

        $this->tokenManager->invalidateToken();
        $reauthAttempted = true;

        $this->logInfo('Graph request unauthorized; invalidating token and retrying once', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'attempt' => $attempt,
            'duration_ms' => $durationMs,
        ]);

        return true;
    }

    private function retryRateLimitedRequest(
        RequestException $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        int $attempt,
        int $durationMs,
    ): bool {
        $retryAfter = $this->retryAfterSeconds($e);

        Event::dispatch(new RateLimitHit(
            driver: 'ms-graph',
            mailbox: (string) $mailbox,
            retryAfter: $retryAfter,
            endpoint: $endpoint,
        ));

        $this->logInfo('Graph rate limit hit; scheduling retry', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'attempt' => $attempt,
            'retry_after_seconds' => $retryAfter,
            'duration_ms' => $durationMs,
        ]);

        if ($this->handleQueueRetry($retryAfter, '429 rate limit')) {
            throw new RateLimitException(
                retryAfter: $retryAfter,
                mailbox: (string) $mailbox,
                message: sprintf("Rate limited for mailbox '%s'. Queue job released for retry.", $mailbox),
            );
        }

        sleep($retryAfter);

        return true;
    }

    private function retryServerErrorRequest(
        int $status,
        string $method,
        string $endpoint,
        ?string $mailbox,
        int $attempt,
        int $durationMs,
    ): bool {
        $backoff = $this->backoffSeconds($attempt);

        $this->logDebug('Graph server error; applying retry backoff', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'attempt' => $attempt,
            'status' => $status,
            'backoff_seconds' => $backoff,
            'duration_ms' => $durationMs,
        ]);

        if ($this->handleQueueRetry($backoff, sprintf('%d server error', $status))) {
            throw new ProviderServerException(
                statusCode: $status,
                attemptsExhausted: $attempt,
                message: sprintf('Microsoft Graph returned %d. Queue job released for retry in %d seconds.', $status, $backoff),
            );
        }

        sleep($backoff);

        return true;
    }

    private function logRequestFailureWithoutRetry(
        string $method,
        string $endpoint,
        ?string $mailbox,
        int $attempt,
        ?int $status,
        int $durationMs,
    ): void {
        $this->logInfo('Graph request failed without retry', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'attempt' => $attempt,
            'status' => $status,
            'duration_ms' => $durationMs,
        ]);
    }

    protected function wrapUnexpectedThrowable(
        \Throwable $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        string $mailboxKey,
        int $attempt,
    ): ApiRequestException {
        $this->logInfo('Graph request failed with unexpected throwable', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'attempt' => $attempt,
            'error' => $e->getMessage(),
        ]);

        return new ApiRequestException(
            message: sprintf('Graph request failed for endpoint %s: %s', $endpoint, $e->getMessage()),
            endpoint: $endpoint,
            previous: $e,
        );
    }

    protected function throwMappedException(
        RequestException $e,
        ?string $mailbox,
        string $mailboxKey,
        string $endpoint,
        int $attempt,
    ): never {
        $status = $e->getResponse()?->getStatusCode();

        if ($status === 403) {
            $this->throwAccessDenied($e, $mailbox, $endpoint, $attempt);
        }

        if ($status === 404) {
            $this->throwResourceNotFound($e, $mailbox, $endpoint, $attempt);
        }

        if ($status === 401) {
            $this->throwAuthenticationFailed($e, $mailbox, $endpoint, $attempt);
        }

        if ($status === 429) {
            $this->throwRateLimitExceeded($e, $mailbox, $endpoint, $attempt);
        }

        if ($status !== null && $status >= 500) {
            $this->throwServerError($e, $status, $mailbox, $endpoint, $attempt);
        }

        Event::dispatch(new ApiError(
            driver: 'ms-graph',
            mailbox: (string) $mailbox,
            status: $status ?? 0,
            error: $e->getMessage(),
            endpoint: $endpoint,
        ));

        throw new ApiRequestException(
            message: $e->getMessage(),
            status: $status,
            endpoint: $endpoint,
            previous: $e,
        );
    }

    private function throwAccessDenied(RequestException $e, ?string $mailbox, string $endpoint, int $attempt): never
    {
        Event::dispatch(new AccessDenied(driver: 'ms-graph', mailbox: (string) $mailbox, endpoint: $endpoint));

        $this->logInfo('Graph access denied response', [
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'status' => 403,
            'attempt' => $attempt,
        ]);

        throw new MailboxAccessDeniedException(
            mailbox: (string) $mailbox,
            message: sprintf(
                "Access denied to mailbox '%s'. Ensure Application Access Policy is configured and propagated.",
                $mailbox,
            ),
            guidance: 'Run Test-ApplicationAccessPolicy in Exchange PowerShell.',
            previous: $e,
        );
    }

    private function throwResourceNotFound(RequestException $e, ?string $mailbox, string $endpoint, int $attempt): never
    {
        $this->logInfo('Graph resource not found response', [
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'status' => 404,
            'attempt' => $attempt,
        ]);

        throw new ResourceNotFoundException(
            resourceType: 'resource',
            resourceId: $endpoint,
            message: sprintf("Resource '%s' was not found.", $endpoint),
            previous: $e,
        );
    }

    private function throwAuthenticationFailed(RequestException $e, ?string $mailbox, string $endpoint, int $attempt): never
    {
        $this->logInfo('Graph authentication failed after retry', [
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'status' => 401,
            'attempt' => $attempt,
        ]);

        throw new AuthenticationException(
            'Authentication with Microsoft Graph failed after token refresh attempt.',
            'Check credentials and tenant configuration.',
            previous: $e,
        );
    }

    private function throwRateLimitExceeded(RequestException $e, ?string $mailbox, string $endpoint, int $attempt): never
    {
        $retryAfter = $this->retryAfterSeconds($e);

        $this->logInfo('Graph request exhausted rate limit retries', [
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'status' => 429,
            'attempt' => $attempt,
            'retry_after_seconds' => $retryAfter,
        ]);

        throw new RateLimitException(
            retryAfter: $retryAfter,
            mailbox: (string) $mailbox,
            message: sprintf("Rate limit exceeded for mailbox '%s'. Retry after %d seconds.", $mailbox, $retryAfter),
            previous: $e,
        );
    }

    private function throwServerError(RequestException $e, int $status, ?string $mailbox, string $endpoint, int $attempt): never
    {
        $this->logInfo('Graph request exhausted server error retries', [
            'endpoint' => $endpoint,
            'mailbox' => $mailbox,
            'status' => $status,
            'attempt' => $attempt,
        ]);

        throw new ProviderServerException(
            statusCode: $status,
            attemptsExhausted: $attempt,
            message: sprintf('Microsoft Graph returned %d after %d attempts.', $status, $attempt),
            previous: $e,
        );
    }
}
