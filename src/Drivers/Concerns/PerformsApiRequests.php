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
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Exceptions\ProviderTransportException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\ProviderWriteResponse;
use Pyle\Mailbox\Support\ResponseBudget;

trait PerformsApiRequests
{
    private Client $client;

    private int $maxRetries;

    private int $retryBackoffBase;

    private string $apiBaseUri;

    private bool $queueReleaseAllowed = true;

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
    public function getBounded(#[\SensitiveParameter] string $endpoint, ResponseBudget $budget, #[\SensitiveParameter] array $query = [], ?string $mailbox = null, bool $retry = false): array
    {
        /** @var array<string, mixed> $response */
        $response = $this->readBounded($endpoint, $budget, $query, $mailbox, $retry, true);

        return $response;
    }

    /** @param array<string, mixed> $query */
    public function getBoundedObject(#[\SensitiveParameter] string $endpoint, ResponseBudget $budget, #[\SensitiveParameter] array $query = [], ?string $mailbox = null, bool $retry = false): \stdClass
    {
        /** @var \stdClass $response */
        $response = $this->readBounded($endpoint, $budget, $query, $mailbox, $retry, false);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|\stdClass
     */
    private function readBounded(#[\SensitiveParameter] string $endpoint, ResponseBudget $budget, #[\SensitiveParameter] array $query, ?string $mailbox, bool $retry, bool $associative): array|\stdClass
    {
        $queueReleaseAllowed = $this->queueReleaseAllowed;
        $this->queueReleaseAllowed = false;
        $attempt = 0;
        $reauthAttempted = false;
        $safeEndpoint = $this->redactedEndpoint($endpoint);
        $mailboxKey = $this->mailboxKey($mailbox);

        try {
            while (true) {
                if ($budget->remaining() === 0) {
                    throw new ResponseLimitExceededException('The provider response byte budget is exhausted.');
                }
                $attempt++;
                $startedAt = microtime(true);
                try {
                    return $this->requestBounded($endpoint, $budget, $query, $mailbox, $associative);
                } catch (RequestException $e) {
                    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                    if ($retry && $this->shouldRetryRequest($e, 'GET', $safeEndpoint, $mailbox, $mailboxKey, $attempt, $durationMs, $reauthAttempted)) {
                        continue;
                    }
                    $this->throwMappedException($e, $mailbox, $mailboxKey, $safeEndpoint, $attempt);
                } catch (ApiRequestException $e) {
                    $previous = $e->getPrevious();
                    if ($retry && ($previous instanceof ConnectException || ($previous instanceof RequestException && $previous->getResponse() === null))
                        && $this->retryTransportFailure($previous, 'GET', $safeEndpoint, $mailboxKey, $attempt, $startedAt)) {
                        continue;
                    }
                    throw $e;
                }
            }
        } finally {
            $this->queueReleaseAllowed = $queueReleaseAllowed;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|\stdClass
     */
    private function requestBounded(#[\SensitiveParameter] string $endpoint, ResponseBudget $budget, #[\SensitiveParameter] array $query, ?string $mailbox, bool $associative): array|\stdClass
    {
        $budget->assertMemoryAvailable(65536);
        $options = $query === [] ? [] : ['query' => $query];
        $response = $this->requestRaw('GET', $endpoint, [...$options, 'stream' => true, 'http_errors' => false, 'decode_content' => false, 'headers' => ['Accept-Encoding' => 'identity']], $mailbox, retry: false);
        $stream = $response->getBody();
        $body = '';

        try {
            try {
                $this->assertIdentityResponse($response);
                $declared = $this->responseLength($response);
                $size = 0;
                while (true) {
                    $budget->assertMemoryAvailable(strlen($body) + 32768);
                    $chunk = $stream->read(min(8192, $budget->remaining()) + 1);
                    if ($chunk === '') {
                        if ($stream->eof()) {
                            break;
                        }
                        throw new ApiRequestException('The provider response stream stopped making progress.');
                    }
                    $budget->consume(strlen($chunk));
                    $size += strlen($chunk);
                    $body .= $chunk;
                }
                if ($declared !== null && $size !== $declared) {
                    throw new ApiRequestException('The provider response length does not match its declaration.');
                }
            } finally {
                $stream->close();
            }
        } catch (ResponseLimitExceededException|ApiRequestException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ApiRequestException('The provider response stream could not be read.');
        }

        $budget->assertJsonDecodeMemory($body);

        if ($response->getStatusCode() >= 400) {
            $endpoint = $this->redactedEndpoint($endpoint);
            $message = $this->providerLabel().' request failed with HTTP '.$response->getStatusCode().'.';
            if ($this->driverKey() === 'ms-graph' && in_array($response->getStatusCode(), [400, 404, 410], true)
                && $this->graphSyncResetCode($body) !== null) {
                throw new ApiRequestException($message, status: $response->getStatusCode(), endpoint: $endpoint, providerCode: 'syncStateNotFound');
            }
            $request = new Request('GET', $endpoint);
            $failure = new RequestException($message, $request, $response);
            $failure = new RequestException($message, $request, new Response($response->getStatusCode(), [
                'Retry-After' => (string) $this->retryAfterSeconds($failure),
            ]));
            throw $failure;
        }

        try {
            $decoded = json_decode($body, $associative, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiRequestException('The provider returned invalid JSON for a bounded read.');
        }

        if (! str_starts_with(ltrim($body), '{') || ($associative ? ! is_array($decoded) : ! ($decoded instanceof \stdClass))) {
            throw new ApiRequestException('The provider returned an invalid object for a bounded read.');
        }

        return $decoded;
    }

    private function graphSyncResetCode(#[\SensitiveParameter] string $body): ?string
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        while (is_array($error)) {
            if (($error['code'] ?? null) === 'syncStateNotFound') {
                return 'syncStateNotFound';
            }
            $error = $error['innerError'] ?? $error['innererror'] ?? null;
        }

        return null;
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

        if ($this->continuationResourcePath($target->getPath()) !== $this->continuationResourcePath($current->getPath()) || $target->getQuery() === '') {
            throw new ApiRequestException('The provider continuation does not match the requested resource.');
        }

        return (string) $target;
    }

    private function continuationResourcePath(string $path): string
    {
        if ($this->driverKey() !== 'ms-graph') {
            return $path;
        }
        $segments = explode('/', $path);
        if (isset($segments[4]) && preg_match("/^mailfolders\\('((?:[^']|'')+)'\\)$/iD", $segments[4], $match) === 1) {
            array_splice($segments, 4, 1, ['mailFolders', rawurlencode(str_replace("''", "'", rawurldecode($match[1])))]);
        }
        if (isset($segments[4]) && strcasecmp($segments[4], 'mailFolders') === 0) {
            $segments[4] = 'mailFolders';
            if (isset($segments[5])) {
                $segments[5] = rawurlencode(rawurldecode($segments[5]));
            }
            if (isset($segments[6]) && strcasecmp($segments[6], 'messages') === 0) {
                $segments[6] = 'messages';
                if (isset($segments[7]) && strcasecmp($segments[7], 'delta') === 0) {
                    $segments[7] = 'delta';
                }
            } elseif (isset($segments[6]) && strcasecmp($segments[6], 'childFolders') === 0) {
                $segments[6] = 'childFolders';
            }
        }

        return implode('/', $segments);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(#[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $payload = [], ?string $mailbox = null): array
    {
        return $this->request('POST', $endpoint, ['json' => $payload], $mailbox);
    }

    /** @param array<string, mixed> $options */
    public function requestOnce(string $method, #[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $options = [], ?string $mailbox = null, int $maxResponseBytes = 65_536): ProviderWriteResponse
    {
        if ($maxResponseBytes < 1) {
            throw new \InvalidArgumentException('The one-attempt response byte budget must be positive.');
        }
        $target = $this->resolveTarget($endpoint, $mailbox);
        $mailboxKey = $this->mailboxKey($mailbox);

        return $this->exchangeOnce($method, $target, $options, $mailboxKey, $maxResponseBytes);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  (\Closure(): void)|null  $beforeRequest
     */
    private function exchangeOnce(string $method, #[\SensitiveParameter] string $target, #[\SensitiveParameter] array $options, string $mailboxKey, int $maxResponseBytes, bool $authenticated = true, ?\Closure $beforeRequest = null): ProviderWriteResponse
    {
        $attempted = false;
        try {
            $response = $this->rateLimiter->forMailbox($this->driverKey(), $mailboxKey, function () use ($method, $target, $options, $mailboxKey, $authenticated, $beforeRequest, &$attempted): ResponseInterface {
                $client = $this->client;
                $requestOptions = $options;
                if ($authenticated) {
                    $requestOptions = $this->buildRequestOptions($options, $mailboxKey);
                } else {
                    $configuration = $client->getConfig();
                    foreach (['headers', 'auth', 'cookies', 'query', 'body', 'json', 'multipart', 'form_params'] as $name) {
                        unset($configuration[$name]);
                    }
                    $configuration['headers'] = [];
                    if (isset($configuration['curl']) && is_array($configuration['curl'])) {
                        foreach (['CURLOPT_USERPWD', 'CURLOPT_USERNAME', 'CURLOPT_PASSWORD', 'CURLOPT_HTTPAUTH', 'CURLOPT_XOAUTH2_BEARER',
                            'CURLOPT_LOGIN_OPTIONS', 'CURLOPT_NETRC', 'CURLOPT_NETRC_FILE', 'CURLOPT_COOKIE', 'CURLOPT_COOKIEFILE',
                            'CURLOPT_COOKIEJAR', 'CURLOPT_COOKIELIST', 'CURLOPT_COOKIESESSION', 'CURLOPT_HTTPHEADER',
                            'CURLOPT_USERAGENT', 'CURLOPT_REFERER', 'CURLOPT_ENCODING', 'CURLOPT_ACCEPT_ENCODING', 'CURLOPT_HTTP_CONTENT_DECODING',
                            'CURLOPT_URL', 'CURLOPT_CURLU', 'CURLOPT_PORT', 'CURLOPT_REQUEST_TARGET', 'CURLOPT_FOLLOWLOCATION',
                            'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPGET', 'CURLOPT_POST', 'CURLOPT_PUT', 'CURLOPT_NOBODY', 'CURLOPT_UPLOAD',
                            'CURLOPT_POSTFIELDS', 'CURLOPT_POSTFIELDSIZE', 'CURLOPT_POSTFIELDSIZE_LARGE', 'CURLOPT_MIMEPOST', 'CURLOPT_HTTPPOST',
                            'CURLOPT_READFUNCTION', 'CURLOPT_READDATA', 'CURLOPT_INFILE', 'CURLOPT_INFILESIZE', 'CURLOPT_INFILESIZE_LARGE',
                            'CURLOPT_VERBOSE', 'CURLOPT_STDERR', 'CURLOPT_HEADER', 'CURLOPT_RETURNTRANSFER'] as $option) {
                            if (defined($option)) {
                                unset($configuration['curl'][constant($option)]);
                            }
                        }
                    }
                    $client = new Client($configuration);
                }
                foreach (array_keys($requestOptions['headers']) as $name) {
                    if (is_string($name) && strcasecmp($name, 'Accept-Encoding') === 0) {
                        unset($requestOptions['headers'][$name]);
                    }
                }
                $requestOptions['headers']['Accept-Encoding'] = 'identity';
                $beforeRequest?->__invoke();
                $attempted = true;

                return $client->request($method, $target, array_merge($requestOptions, [
                    'allow_redirects' => false, 'debug' => false, 'http_errors' => false, 'stream' => true, 'decode_content' => false,
                ]));
            });
        } catch (\Throwable $e) {
            if (! $attempted) {
                if ($e instanceof GrantAccessDeniedException || $e instanceof GrantRefreshException || $e instanceof RateLimitException) {
                    throw $e;
                }
                throw new ApiRequestException($this->providerLabel().' submission preflight failed.');
            }
            $response = $e instanceof RequestException ? $e->getResponse() : null;
            if ($response === null) {
                return new ProviderWriteResponse(null);
            }
        }

        $headers = [];
        foreach (['date', 'retry-after', 'request-id', 'x-request-id', 'client-request-id', 'x-ms-request-id', 'x-guploader-uploadid'] as $name) {
            $values = $response->getHeader($name);
            $pattern = in_array($name, ['date', 'retry-after'], true)
                ? '/\A[0-9A-Za-z ,:-]{1,128}\z/'
                : '/\A[A-Za-z0-9][A-Za-z0-9._:+\/=-]{0,1023}\z/';
            $safe = array_filter($values, static fn (string $value): bool => preg_match($pattern, $value) === 1 && ! str_contains($value, '://'));
            if ($values !== [] && $values === $safe) {
                $headers[$name] = array_values($values);
            }
        }
        $stream = $response->getBody();
        $body = '';
        try {
            if (! in_array(strtolower(trim($response->getHeaderLine('Content-Encoding'))), ['', 'identity'], true)) {
                return new ProviderWriteResponse($response->getStatusCode(), $headers);
            }
            while (true) {
                $chunk = $stream->read(min(8192, $maxResponseBytes - strlen($body) + 1));
                if ($chunk === '' && $stream->eof()) {
                    break;
                }
                if ($chunk === '' || strlen($body) + strlen($chunk) > $maxResponseBytes) {
                    return new ProviderWriteResponse($response->getStatusCode(), $headers);
                }
                $body .= $chunk;
            }
        } catch (\Throwable) {
            return new ProviderWriteResponse($response->getStatusCode(), $headers);
        } finally {
            try {
                $stream->close();
            } catch (\Throwable) {
                // Cleanup cannot erase an already observed provider acknowledgement.
            }
        }

        $contentLength = $response->getHeaderLine('Content-Length');
        if ($response->hasHeader('Content-Length') && in_array(strtolower(trim($response->getHeaderLine('Content-Encoding'))), ['', 'identity'], true)
            && (! ctype_digit($contentLength) || ltrim($contentLength, '0') !== ltrim((string) strlen($body), '0'))) {
            return new ProviderWriteResponse($response->getStatusCode(), $headers);
        }

        $locations = $response->getHeader('Location');

        return new ProviderWriteResponse($response->getStatusCode(), $headers, $body,
            count($locations) === 1 && strlen($locations[0]) <= 16_384 ? $locations[0] : null);
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

    public function streamBounded(#[\SensitiveParameter] string $endpoint, ResponseBudget $budget, int $maxBytes, ?string $mailbox = null): StreamInterface
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('A streamed response byte limit must be positive.');
        }
        $budget->assertMemoryAvailable(65536);
        $response = $this->requestRaw('GET', $endpoint, ['stream' => true, 'http_errors' => false, 'decode_content' => false, 'headers' => ['Accept-Encoding' => 'identity']], $mailbox, retry: false);
        $source = $response->getBody();
        $temporary = null;
        $size = 0;

        try {
            $budget->assertMemoryAvailable(65536);
            $this->assertIdentityResponse($response);
            $declared = $this->responseLength($response);
            if ($declared !== null && ($declared > $maxBytes || $declared > $budget->remaining())) {
                throw new ResponseLimitExceededException('The declared attachment exceeds its remaining byte limit.');
            }
            $temporary = @tmpfile();
            if ($temporary === false) {
                throw new ApiRequestException('An owned temporary response stream could not be created.');
            }
            while (true) {
                $budget->assertMemoryAvailable(65536);
                $chunk = $source->read(min(8192, $budget->remaining()) + 1);
                if ($chunk === '') {
                    if ($source->eof()) {
                        break;
                    }
                    throw new ApiRequestException('The provider response stream stopped making progress.');
                }
                $budget->consume(strlen($chunk));
                if (strlen($chunk) > $maxBytes - $size) {
                    throw new ResponseLimitExceededException('The streamed attachment exceeds its file byte limit.');
                }
                $size += strlen($chunk);
                if (@fwrite($temporary, $chunk) !== strlen($chunk)) {
                    throw new ApiRequestException('The owned temporary response stream could not be written completely.');
                }
            }
            if ($response->getStatusCode() !== 200) {
                throw new ApiRequestException('The provider file read failed with HTTP '.$response->getStatusCode().'.', status: $response->getStatusCode());
            }
            if ($declared !== null && $size !== $declared) {
                throw new ApiRequestException('The provider response length does not match its declaration.');
            }
            rewind($temporary);
            $stream = Utils::streamFor($temporary);
            $temporary = null;

            return $stream;
        } catch (ResponseLimitExceededException|ApiRequestException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ApiRequestException('The provider response stream could not be read completely.');
        } finally {
            $source->close();
            if (is_resource($temporary)) {
                fclose($temporary);
            }
        }
    }

    private function assertIdentityResponse(ResponseInterface $response): void
    {
        if (! in_array(strtolower(trim($response->getHeaderLine('Content-Encoding'))), ['', 'identity'], true)) {
            throw new ApiRequestException('The bounded provider read requires identity HTTP content encoding.');
        }
    }

    private function responseLength(ResponseInterface $response): ?int
    {
        $values = $response->getHeader('Content-Length');
        if ($values === []) {
            return null;
        }
        if (count($values) !== 1 || ! ctype_digit($values[0])) {
            throw new ApiRequestException('The provider returned a malformed response length.');
        }
        $length = ltrim($values[0], '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($length) > strlen($maximum) || (strlen($length) === strlen($maximum) && strcmp($length, $maximum) > 0)) {
            throw new ApiRequestException('The provider returned an unsupported response length.');
        }

        return (int) $length;
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
            $response->getBody()->close();
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
        if (! $this->queueReleaseAllowed) {
            return false;
        }
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
