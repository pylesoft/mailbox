<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
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
    private Client $client;

    private int $maxRetries;

    private int $retryBackoffBase;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly TokenManager $tokenManager,
        private readonly RateLimiter $rateLimiter,
        ?Client $client = null,
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => sprintf('https://graph.microsoft.com/%s/', $this->config['api_version'] ?? 'v1.0'),
            'timeout' => (int) ($this->config['timeout'] ?? 30),
        ]);

        $this->maxRetries = (int) ($this->config['max_retries'] ?? config('mailbox.max_retries', 3));
        $this->retryBackoffBase = (int) ($this->config['retry_backoff_base'] ?? config('mailbox.retry_backoff_base', 2));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
    {
        return $this->request('GET', $endpoint, ['query' => $query], $mailbox);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
    {
        return $this->request('POST', $endpoint, ['json' => $payload], $mailbox);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function patch(string $endpoint, array $payload = [], ?string $mailbox = null): array
    {
        return $this->request('PATCH', $endpoint, ['json' => $payload], $mailbox);
    }

    public function delete(string $endpoint, ?string $mailbox = null): void
    {
        $this->request('DELETE', $endpoint, [], $mailbox);
    }

    public function stream(string $endpoint, ?string $mailbox = null): StreamInterface
    {
        $response = $this->requestRaw('GET', $endpoint, ['stream' => true], $mailbox);

        return $response->getBody();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $endpoint, array $options = [], ?string $mailbox = null): array
    {
        $response = $this->requestRaw($method, $endpoint, $options, $mailbox);

        if ($response->getStatusCode() === 204) {
            return [];
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $options */
    private function requestRaw(string $method, string $endpoint, array $options = [], ?string $mailbox = null): ResponseInterface
    {
        $mailboxKey = $mailbox ?? 'global';

        return $this->rateLimiter->forMailbox($mailboxKey, function () use ($method, $endpoint, $options, $mailbox): ResponseInterface {
            $attempt = 0;
            $reauthAttempted = false;

            while (true) {
                $attempt++;

                try {
                    $headers = [
                        'Authorization' => 'Bearer '.$this->tokenManager->getToken(),
                        'Accept' => 'application/json',
                    ];

                    if ((bool) ($this->config['prefer_immutable_ids'] ?? config('mailbox.prefer_immutable_ids', true))) {
                        $headers['Prefer'] = 'IdType="ImmutableId"';
                    }

                    $requestOptions = array_merge($options, ['headers' => array_merge($headers, $options['headers'] ?? [])]);

                    $target = str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')
                        ? $endpoint
                        : ltrim($endpoint, '/');

                    $response = $this->client->request($method, $target, $requestOptions);

                    $this->logDebug('Graph request completed', [
                        'method' => $method,
                        'endpoint' => $endpoint,
                        'status' => $response->getStatusCode(),
                        'attempt' => $attempt,
                    ]);

                    return $response;
                } catch (RequestException $e) {
                    $status = $e->getResponse()?->getStatusCode();
                    $retryAfter = $this->retryAfterSeconds($e);

                    if ($status === 401 && $reauthAttempted === false) {
                        $this->tokenManager->invalidateToken();
                        $reauthAttempted = true;

                        continue;
                    }

                    if ($status === 429 && $attempt <= $this->maxRetries) {
                        Event::dispatch(new RateLimitHit(
                            driver: 'ms-graph',
                            mailbox: $mailbox ?? '',
                            retryAfter: $retryAfter,
                            endpoint: $endpoint,
                        ));

                        if ($this->handleQueueRetry($retryAfter, '429 rate limit')) {
                            throw new RateLimitException(
                                retryAfter: $retryAfter,
                                mailbox: (string) $mailbox,
                                message: sprintf("Rate limited for mailbox '%s'. Queue job released for retry.", $mailbox),
                            );
                        }

                        sleep($retryAfter);

                        continue;
                    }

                    if ($status !== null && $status >= 500 && $attempt <= $this->maxRetries) {
                        $backoff = $this->backoffSeconds($attempt);

                        if ($this->handleQueueRetry($backoff, sprintf('%d server error', $status))) {
                            throw new ProviderServerException(
                                statusCode: $status,
                                attemptsExhausted: $attempt,
                                message: sprintf('Microsoft Graph returned %d. Queue job released for retry in %d seconds.', $status, $backoff),
                            );
                        }

                        sleep($backoff);

                        continue;
                    }

                    $this->throwMappedException($e, $mailbox, $endpoint, $attempt);
                } catch (\Throwable $e) {
                    throw new ApiRequestException(
                        message: sprintf('Graph request failed for endpoint %s: %s', $endpoint, $e->getMessage()),
                        endpoint: $endpoint,
                        previous: $e,
                    );
                }
            }
        });
    }

    private function throwMappedException(RequestException $e, ?string $mailbox, string $endpoint, int $attempt): never
    {
        $status = $e->getResponse()?->getStatusCode();

        if ($status === 403) {
            Event::dispatch(new AccessDenied(driver: 'ms-graph', mailbox: (string) $mailbox, endpoint: $endpoint));

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

        if ($status === 404) {
            throw new ResourceNotFoundException(
                resourceType: 'resource',
                resourceId: $endpoint,
                message: sprintf("Resource '%s' was not found.", $endpoint),
                previous: $e,
            );
        }

        if ($status === 401) {
            throw new AuthenticationException(
                'Authentication with Microsoft Graph failed after token refresh attempt.',
                'Check credentials and tenant configuration.',
                previous: $e,
            );
        }

        if ($status === 429) {
            $retryAfter = $this->retryAfterSeconds($e);

            throw new RateLimitException(
                retryAfter: $retryAfter,
                mailbox: (string) $mailbox,
                message: sprintf("Rate limit exceeded for mailbox '%s'. Retry after %d seconds.", $mailbox, $retryAfter),
                previous: $e,
            );
        }

        if ($status !== null && $status >= 500) {
            throw new ProviderServerException(
                statusCode: $status,
                attemptsExhausted: $attempt,
                message: sprintf('Microsoft Graph returned %d after %d attempts.', $status, $attempt),
                previous: $e,
            );
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

    private function retryAfterSeconds(RequestException $e): int
    {
        $header = $e->getResponse()?->getHeaderLine('Retry-After');

        if (is_string($header) && ctype_digit($header)) {
            return max(1, (int) $header);
        }

        return 1;
    }

    private function backoffSeconds(int $attempt): int
    {
        return (int) max(1, pow($this->retryBackoffBase, max(0, $attempt - 1)));
    }

    private function handleQueueRetry(int $delaySeconds, string $reason): bool
    {
        $strategy = (string) ($this->config['queue_retry_strategy'] ?? config('mailbox.queue_retry_strategy', 'release'));

        if ($strategy !== 'release' || ! app()->bound('queue.job')) {
            return false;
        }

        $job = app('queue.job');

        if (! $job instanceof QueueJobContract) {
            return false;
        }

        $this->logDebug('Releasing queue job for retry', [
            'delay_seconds' => $delaySeconds,
            'reason' => $reason,
        ]);

        $job->release($delaySeconds);

        return true;
    }

    /** @param array<string, mixed> $context */
    private function logDebug(string $message, array $context = []): void
    {
        $channel = (string) config('mailbox.log_channel', 'stack');

        Log::channel($channel)->debug($message, $context);
    }
}
