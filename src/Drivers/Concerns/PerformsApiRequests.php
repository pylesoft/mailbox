<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Exceptions\ApiRequestException;

trait PerformsApiRequests
{
    private Client $client;

    private int $maxRetries;

    private int $retryBackoffBase;

    /** @param array<string, mixed> $config */
    protected function bootApiClient(array $config, ?Client $client, string $baseUri): void
    {
        $this->client = $client ?? new Client([
            'base_uri' => $baseUri,
            'timeout' => (int) ($config['timeout'] ?? 30),
        ]);

        $this->maxRetries = (int) ($config['max_retries'] ?? config('mailbox.max_retries', 3));
        $this->retryBackoffBase = (int) ($config['retry_backoff_base'] ?? config('mailbox.retry_backoff_base', 2));
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
    {
        return $this->request('GET', $endpoint, ['query' => $query], $mailbox);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
    {
        return $this->request('POST', $endpoint, ['json' => $payload], $mailbox);
    }

    /** @param array<string, mixed> $payload
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

    /** @param array<string, mixed> $options
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
        $mailboxKey = $this->mailboxKey($mailbox);

        return $this->rateLimiter->forMailbox($this->driverKey(), $mailboxKey, function () use ($method, $endpoint, $options, $mailbox, $mailboxKey): ResponseInterface {
            $attempt = 0;
            $reauthAttempted = false;

            while (true) {
                $attempt++;
                $startedAt = microtime(true);

                try {
                    return $this->sendRequest($method, $endpoint, $options, $mailboxKey, $attempt, $startedAt);
                } catch (RequestException $e) {
                    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                    if ($this->shouldRetryRequest($e, $method, $endpoint, $mailbox, $mailboxKey, $attempt, $durationMs, $reauthAttempted)) {
                        continue;
                    }

                    $this->throwMappedException($e, $mailbox, $mailboxKey, $endpoint, $attempt);
                } catch (\Throwable $e) {
                    throw $this->wrapUnexpectedThrowable($e, $method, $endpoint, $mailbox, $mailboxKey, $attempt);
                }
            }
        });
    }

    /** @param array<string, mixed> $options */
    private function sendRequest(
        string $method,
        string $endpoint,
        array $options,
        string $mailboxKey,
        int $attempt,
        float $startedAt,
    ): ResponseInterface {
        $response = $this->client->request(
            $method,
            $this->resolveTarget($endpoint),
            $this->buildRequestOptions($options, $mailboxKey),
        );

        $this->logDebug($this->providerLabel().' request completed', [
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => $response->getStatusCode(),
            'attempt' => $attempt,
            'mailbox' => $mailboxKey,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $response;
    }

    private function resolveTarget(string $endpoint): string
    {
        return str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')
            ? $endpoint
            : ltrim($endpoint, '/');
    }

    protected function retryAfterSeconds(RequestException $e): int
    {
        $header = $e->getResponse()?->getHeaderLine('Retry-After');

        if (is_string($header) && ctype_digit($header)) {
            return max(1, (int) $header);
        }

        return 1;
    }

    protected function backoffSeconds(int $attempt): int
    {
        return (int) max(1, pow($this->retryBackoffBase, max(0, $attempt - 1)));
    }

    protected function handleQueueRetry(int $delaySeconds, string $reason): bool
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
    protected function logDebug(string $message, array $context = []): void
    {
        $channel = (string) config('mailbox.log_channel', 'stack');

        Log::channel($channel)->debug($message, $context);
    }

    /** @param array<string, mixed> $context */
    protected function logInfo(string $message, array $context = []): void
    {
        $channel = (string) config('mailbox.log_channel', 'stack');

        Log::channel($channel)->info($message, $context);
    }

    abstract protected function driverKey(): string;

    abstract protected function providerLabel(): string;

    abstract protected function mailboxKey(?string $mailbox): string;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    abstract protected function buildRequestOptions(array $options, string $mailboxKey): array;

    abstract protected function shouldRetryRequest(
        RequestException $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        string $mailboxKey,
        int $attempt,
        int $durationMs,
        bool &$reauthAttempted,
    ): bool;

    abstract protected function throwMappedException(
        RequestException $e,
        ?string $mailbox,
        string $mailboxKey,
        string $endpoint,
        int $attempt,
    ): never;

    abstract protected function wrapUnexpectedThrowable(
        \Throwable $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        string $mailboxKey,
        int $attempt,
    ): ApiRequestException;
}
