<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\BatchRequest;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMessageQuery;

it('queries messages and maps DTOs', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return [
                'value' => [
                    ['id' => '1', 'subject' => 'A'],
                    ['id' => '2', 'subject' => 'B'],
                ],
            ];
        }
    };

    $query = new MsGraphMessageQuery($client, new BatchRequest($client), 'invoices@example.com');

    $result = $query->where('isRead', false)->take(2)->get();

    expect($result)->toHaveCount(2);
    expect($result->first()?->subject)->toBe('A');
});

it('applies client-side filters when using search with where clauses', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            expect($query)->toHaveKey('$search');

            return [
                'value' => [
                    ['id' => '1', 'subject' => 'Invoice 1001', 'isRead' => false],
                    ['id' => '2', 'subject' => 'Invoice 1002', 'isRead' => true],
                ],
            ];
        }
    };

    $query = new MsGraphMessageQuery($client, new BatchRequest($client), 'invoices@example.com');

    $result = $query
        ->search('invoice')
        ->where('isRead', false)
        ->get();

    expect($result)->toHaveCount(1);
    expect($result->first()?->id)->toBe('1');
});

it('sends bulk actions in chunked batch requests', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $batchPayloads = [];

        public function __construct() {}

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            if ($endpoint === '/$batch') {
                $this->batchPayloads[] = $payload;

                return [
                    'responses' => collect((array) ($payload['requests'] ?? []))
                        ->map(fn (array $request): array => ['id' => $request['id'], 'status' => 200])
                        ->all(),
                ];
            }

            return [];
        }

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return ['value' => []];
        }
    };

    $query = new MsGraphMessageQuery($client, new BatchRequest($client), 'invoices@example.com');
    $ids = array_map(static fn (int $i): string => 'm-'.$i, range(1, 44));

    $query->markAsRead($ids);

    expect($client->batchPayloads)->toHaveCount(3);
    expect((array) $client->batchPayloads[0]['requests'])->toHaveCount(20);
    expect((array) $client->batchPayloads[1]['requests'])->toHaveCount(20);
    expect((array) $client->batchPayloads[2]['requests'])->toHaveCount(4);
});
