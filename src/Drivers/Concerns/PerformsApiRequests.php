<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Exceptions\ProviderTransportException;
use Pyle\Mailbox\Support\ResponseBudget;

trait PerformsApiRequests
{
    private Client $client;

    private int $maxRetries;

    private int $retryBackoffBase;

    private string $apiBaseUri;

    /** @param array<string, mixed> $config */
    protected function bootApiClient(array $config, ?Client $client, string $baseUri): void
    {
        $this->apiBaseUri = $baseUri;
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
    public function get(#[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $query = [], ?string $mailbox = null): array
    {
        return $this->request('GET', $endpoint, $query === [] ? [] : ['query' => $query], $mailbox);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function getBounded(#[\SensitiveParameter] string $endpoint, ResponseBudget $budget, #[\SensitiveParameter] array $query = [], ?string $mailbox = null): array
    {
        $options = $query === [] ? [] : ['query' => $query];
        $response = $this->requestRaw('GET', $endpoint, [...$options, 'stream' => true, 'http_errors' => false], $mailbox, retry: false);
        $stream = $response->getBody();
        $body = '';

        try {
            while (true) {
                $chunk = $stream->read(min(8192, $budget->remaining()) + 1);
                if ($chunk === '') {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new ApiRequestException('The provider response stream stopped making progress.');
                }
                $budget->consume(strlen($chunk));
                $body .= $chunk;
            }
        } finally {
            $stream->close();
        }

        if ($response->getStatusCode() >= 400) {
            $endpoint = $this->redactedEndpoint($endpoint);
            $message = $this->providerLabel().' request failed with HTTP '.$response->getStatusCode().'.';
            $request = new Request('GET', $endpoint);
            $failure = new RequestException($message, $request, $response);
            $failure = new RequestException($message, $request, new Response($response->getStatusCode(), [
                'Retry-After' => (string) $this->retryAfterSeconds($failure),
            ]));
            $this->throwMappedException($failure, $mailbox, $this->mailboxKey($mailbox), $endpoint, 1);
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiRequestException('The provider returned invalid JSON for a bounded read.');
        }

        if (! is_array($decoded) || ! str_starts_with(ltrim($body), '{')) {
            throw new ApiRequestException('The provider returned an invalid object for a bounded read.');
        }

        return $decoded;
    }

    public function continuationEndpoint(#[\SensitiveParameter] mixed $link, #[\SensitiveParameter] string $endpoint, ?string $mailbox = null): string
    {
        if (! is_string($link) || $link === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $link)) {
            throw new ApiRequestException('The provider returned a missing or malformed continuation.');
        }

        $current = new Uri($this->resolveTarget($endpoint, $mailbox));
        try {
            $relative = new Uri($link);
        } catch (\InvalidArgumentException) {
            throw new ApiRequestException('The provider returned a malformed continuation.');
        }

        if ($relative->getQuery() !== (parse_url($link, PHP_URL_QUERY) ?? '')) {
            throw new ApiRequestException('The provider returned a malformed continuation query.');
        }

        $candidate = $relative->getScheme() === '' && ! str_starts_with($link, '/') && ! str_starts_with($link, 'users/')
            ? (string) UriResolver::resolve($current, $relative)
            : $link;
        $target = new Uri($this->resolveTarget($candidate, $mailbox));

        if ($target->getPath() !== $current->getPath() || $target->getQuery() === '') {
            throw new ApiRequestException('The provider continuation does not match the requested resource.');
        }

        return (string) $target;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(#[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $payload = [], ?string $mailbox = null): array
    {
        return $this->request('POST', $endpoint, ['json' => $payload], $mailbox);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function patch(#[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $payload = [], ?string $mailbox = null): array
    {
        return $this->request('PATCH', $endpoint, ['json' => $payload], $mailbox);
    }

    public function delete(#[\SensitiveParameter] string $endpoint, ?string $mailbox = null): void
    {
        $this->request('DELETE', $endpoint, [], $mailbox);
    }

    public function stream(#[\SensitiveParameter] string $endpoint, ?string $mailbox = null): StreamInterface
    {
        $response = $this->requestRaw('GET', $endpoint, ['stream' => true], $mailbox);

        return $response->getBody();
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function request(string $method, #[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $options = [], ?string $mailbox = null): array
    {
        $response = $this->requestRaw($method, $endpoint, $options, $mailbox);

        if ($response->getStatusCode() === 204) {
            return [];
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        try {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $endpoint = $this->redactedEndpoint($endpoint);
            throw new ProviderTransportException(
                message: sprintf('%s returned an invalid JSON response for %s.', $this->providerLabel(), $endpoint),
                endpoint: $endpoint,
                mailbox: $this->mailboxKey($mailbox),
                previous: new JsonException('The provider returned invalid JSON.'),
            );
        }
    }

    /** @param array<string, mixed> $options */
    private function requestRaw(string $method, #[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $options = [], ?string $mailbox = null, bool $retry = true): ResponseInterface
    {
        $target = $this->resolveTarget($endpoint, $mailbox);
        $endpoint = $this->redactedEndpoint($endpoint);
        $mailboxKey = $this->mailboxKey($mailbox);

        return $this->rateLimiter->forMailbox($this->driverKey(), $mailboxKey, function () use ($method, $endpoint, $target, $options, $mailbox, $mailboxKey, $retry): ResponseInterface {
            $attempt = 0;
            $reauthAttempted = false;

            while (true) {
                $attempt++;
                $startedAt = microtime(true);

                try {
                    return $this->sendRequest($method, $target, $options, $mailboxKey, $attempt, $startedAt);
                } catch (ConnectException $e) {
                    $e = new ConnectException($this->providerLabel().' connection failed.', new Request($method, $endpoint));
                    if ($retry && $this->retryTransportFailure($e, $method, $endpoint, $mailboxKey, $attempt, $startedAt)) {
                        continue;
                    }

                    throw $this->wrapUnexpectedThrowable($e, $method, $endpoint, $mailbox, $mailboxKey, $attempt);
                } catch (RequestException $e) {
                    $response = $e->getResponse();
                    $status = $response?->getStatusCode();
                    $e = new RequestException(
                        $this->providerLabel().' request failed'.($status === null ? '.' : ' with HTTP '.$status.'.'),
                        new Request($method, $endpoint),
                        $response === null ? null : new Response($response->getStatusCode(), ['Retry-After' => (string) $this->retryAfterSeconds($e)]),
                    );
                    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                    if ($retry && $e->getResponse() === null && $this->retryTransportFailure($e, $method, $endpoint, $mailboxKey, $attempt, $startedAt)) {
                        continue;
                    }

                    if ($retry && $this->shouldRetryRequest($e, $method, $endpoint, $mailbox, $mailboxKey, $attempt, $durationMs, $reauthAttempted)) {
                        continue;
                    }

                    $this->throwMappedException($e, $mailbox, $mailboxKey, $endpoint, $attempt);
                } catch (GrantAccessDeniedException|GrantRefreshException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    throw $this->wrapUnexpectedThrowable(new \RuntimeException($this->providerLabel().' request failed ('.$e::class.').'), $method, $endpoint, $mailbox, $mailboxKey, $attempt);
                }
            }
        });
    }

    /** @param array<string, mixed> $options */
    private function sendRequest(
        string $method,
        #[\SensitiveParameter] string $endpoint,
        #[\SensitiveParameter] array $options,
        string $mailboxKey,
        int $attempt,
        float $startedAt,
    ): ResponseInterface {
        $response = $this->client->request(
            $method,
            $endpoint,
            array_merge($this->buildRequestOptions($options, $mailboxKey), ['allow_redirects' => false, 'debug' => false]),
        );

        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new ApiRequestException('Authenticated provider requests do not follow redirects.');
        }

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

    private function retryTransportFailure(
        \Throwable $e,
        string $method,
        string $endpoint,
        string $mailboxKey,
        int $attempt,
        float $startedAt,
    ): bool {
        if (! $this->shouldRetryTransportFailure($attempt)) {
            if ($this->transportFailuresAreRetryable()) {
                $this->throwTransportRetriesExhausted($e, $method, $endpoint, $mailboxKey, $attempt, $startedAt);
            }

            return false;
        }

        $backoff = $this->backoffSeconds($attempt);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logInfo($this->providerLabel().' transport failure; scheduling retry', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailboxKey,
            'attempt' => $attempt,
            'backoff_seconds' => $backoff,
            'duration_ms' => $durationMs,
            'error' => $e->getMessage(),
        ]);

        if ($this->handleQueueRetry($backoff, 'transport failure')) {
            throw new ProviderTransportException(
                message: sprintf('%s transport failure. Queue job released for retry in %d seconds.', $this->providerLabel(), $backoff),
                endpoint: $endpoint,
                mailbox: $mailboxKey,
                attemptsExhausted: $attempt,
                retryDelay: $backoff,
                previous: $e,
            );
        }

        sleep($backoff);

        return true;
    }

    private function shouldRetryTransportFailure(int $attempt): bool
    {
        return $this->transportFailuresAreRetryable() && $attempt <= $this->maxRetries;
    }

    private function transportFailuresAreRetryable(): bool
    {
        return (bool) ($this->config['retry_transport_failures'] ?? config('mailbox.retry_transport_failures', true));
    }

    private function throwTransportRetriesExhausted(
        \Throwable $e,
        string $method,
        string $endpoint,
        string $mailboxKey,
        int $attempt,
        float $startedAt,
    ): never {
        $this->logInfo($this->providerLabel().' transport failure retries exhausted', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailboxKey,
            'attempt' => $attempt,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $e->getMessage(),
        ]);

        throw new ProviderTransportException(
            message: sprintf('%s transport failure after %d attempts: %s', $this->providerLabel(), $attempt, $e->getMessage()),
            endpoint: $endpoint,
            mailbox: $mailboxKey,
            attemptsExhausted: $attempt,
            previous: $e,
        );
    }

    private function resolveTarget(#[\SensitiveParameter] string $endpoint, ?string $mailbox = null): string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint) || str_starts_with($endpoint, '//')) {
            throw new ApiRequestException('The authenticated request destination is invalid.');
        }

        $base = new Uri($this->apiBaseUri);
        $basePath = $base->getPath();
        $relative = str_starts_with($endpoint, '/') && ! str_starts_with($endpoint, $basePath)
            ? ltrim($endpoint, '/')
            : $endpoint;

        try {
            $uri = UriResolver::resolve($base, new Uri($relative));
        } catch (\InvalidArgumentException) {
            throw new ApiRequestException('The authenticated request destination is invalid.');
        }

        $decodedPath = rawurldecode($uri->getPath());
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decodedPath)
            || in_array('.', explode('/', $decodedPath), true) || in_array('..', explode('/', $decodedPath), true)) {
            throw new ApiRequestException('The authenticated request destination is invalid.');
        }

        if ($uri->getScheme() !== $base->getScheme() || $uri->getHost() !== $base->getHost()
            || $uri->getPort() !== $base->getPort() || $uri->getUserInfo() !== '' || $uri->getFragment() !== ''
            || ! str_starts_with($uri->getPath(), $basePath)) {
            throw new ApiRequestException('The authenticated request destination is not trusted.');
        }

        return (string) $uri;
    }

    private function assertMailboxTarget(#[\SensitiveParameter] string $target, ?string $mailbox, bool $allowMe = false): void
    {
        $basePath = (new Uri($this->apiBaseUri))->getPath();
        $segments = explode('/', substr((new Uri($target))->getPath(), strlen($basePath)));

        if ($mailbox !== null && ($segments[0] !== 'users' || ! isset($segments[1])
            || (strcasecmp(rawurldecode($segments[1]), trim($mailbox)) !== 0 && ! ($allowMe && $segments[1] === 'me')))) {
            throw new ApiRequestException('The authenticated request destination does not match the selected mailbox.');
        }
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
        if (isset($context['endpoint']) && is_string($context['endpoint'])) {
            $context['endpoint'] = $this->redactedEndpoint($context['endpoint']);
        }
        $channel = (string) config('mailbox.log_channel', 'stack');

        Log::channel($channel)->debug($message, $context);
    }

    /** @param array<string, mixed> $context */
    protected function logInfo(string $message, array $context = []): void
    {
        if (isset($context['endpoint']) && is_string($context['endpoint'])) {
            $context['endpoint'] = $this->redactedEndpoint($context['endpoint']);
        }
        $channel = (string) config('mailbox.log_channel', 'stack');

        Log::channel($channel)->info($message, $context);
    }

    private function redactedEndpoint(#[\SensitiveParameter] string $endpoint): string
    {
        return explode('?', $endpoint, 2)[0];
    }

    /** @return array{provider: string} */
    public function __debugInfo(): array
    {
        return ['provider' => $this->providerLabel()];
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
