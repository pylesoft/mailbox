<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Pyle\Mailbox\Exceptions\ApiRequestException;

class BatchRequest
{
    private const MAX_BATCH_SIZE = 20;

    public function __construct(
        private readonly GraphClient $client,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, mixed>
     */
    public function send(array $requests): array
    {
        if ($requests === []) {
            return ['responses' => []];
        }

        $mergedResponses = [];

        foreach (array_chunk($requests, self::MAX_BATCH_SIZE) as $chunk) {
            $requestsById = [];
            foreach ($chunk as $request) {
                $id = isset($request['id']) ? (string) $request['id'] : '';
                if ($id === '') {
                    continue;
                }

                $requestsById[$id] = $request;
            }

            $response = $this->client->post('/$batch', [
                'requests' => $chunk,
            ]);

            $responses = $response['responses'] ?? null;
            if (! is_array($responses)) {
                throw new ApiRequestException('Batch response is missing the responses array.', endpoint: '/$batch');
            }

            $failed = [];

            foreach ($responses as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $status = isset($entry['status']) ? (int) $entry['status'] : 0;
                if ($status >= 400) {
                    $id = isset($entry['id']) ? (string) $entry['id'] : '';
                    if ($id === '' || ! isset($requestsById[$id])) {
                        throw new ApiRequestException(
                            message: sprintf('Batch subrequest failed with status %d and cannot be retried.', $status),
                            status: $status,
                            endpoint: '/$batch',
                        );
                    }

                    $failed[] = $requestsById[$id];

                    continue;
                }

                $mergedResponses[] = $entry;
            }

            foreach ($failed as $failedRequest) {
                $mergedResponses[] = $this->retryIndividually($failedRequest);
            }
        }

        return ['responses' => $mergedResponses];
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function retryIndividually(array $request): array
    {
        $id = isset($request['id']) ? (string) $request['id'] : '';
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $url = (string) ($request['url'] ?? '');
        $body = isset($request['body']) && is_array($request['body']) ? $request['body'] : [];

        if ($id === '' || $url === '') {
            throw new ApiRequestException('Batch subrequest retry is missing id or url.', endpoint: '/$batch');
        }

        try {
            return match ($method) {
                'GET' => $this->retryGet($id, $url),
                'POST' => [
                    'id' => $id,
                    'status' => 200,
                    'body' => $this->client->post($url, $body),
                ],
                'PATCH' => [
                    'id' => $id,
                    'status' => 200,
                    'body' => $this->client->patch($url, $body),
                ],
                'DELETE' => $this->retryDelete($id, $url),
                default => throw new ApiRequestException(
                    sprintf('Unsupported batch retry method %s.', $method),
                    endpoint: '/$batch',
                ),
            };
        } catch (ApiRequestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiRequestException(
                message: sprintf('Batch subrequest %s failed after individual retry: %s', $id, $e->getMessage()),
                endpoint: '/$batch',
                previous: $e,
            );
        }
    }

    /** @return array<string, mixed> */
    private function retryGet(string $id, string $url): array
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? $url);
        $query = [];

        if (isset($parts['query']) && $parts['query'] !== '') {
            $parsed = [];
            parse_str($parts['query'], $parsed);

            foreach ($parsed as $key => $value) {
                $query[(string) $key] = $value;
            }
        }

        return [
            'id' => $id,
            'status' => 200,
            'body' => $this->client->get($path, $query),
        ];
    }

    /** @return array<string, mixed> */
    private function retryDelete(string $id, string $url): array
    {
        $this->client->delete($url);

        return [
            'id' => $id,
            'status' => 204,
            'body' => [],
        ];
    }
}
