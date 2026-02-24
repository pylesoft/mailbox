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
            $response = $this->client->post('/$batch', [
                'requests' => $chunk,
            ]);

            $responses = $response['responses'] ?? null;
            if (! is_array($responses)) {
                throw new ApiRequestException('Batch response is missing the responses array.', endpoint: '/$batch');
            }

            foreach ($responses as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $status = isset($entry['status']) ? (int) $entry['status'] : 0;
                if ($status >= 400) {
                    throw new ApiRequestException(
                        message: sprintf('Batch subrequest failed with status %d.', $status),
                        status: $status,
                        endpoint: '/$batch',
                    );
                }

                $mergedResponses[] = $entry;
            }
        }

        return ['responses' => $mergedResponses];
    }
}
