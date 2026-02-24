<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

class BatchRequest
{
    public function __construct(
        private readonly GraphClient $client,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $requests
     * @return array<string, mixed>
     */
    public function send(array $requests): array
    {
        return $this->client->post('/$batch', [
            'requests' => $requests,
        ]);
    }
}
