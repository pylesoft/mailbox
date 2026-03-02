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

it('applies internetMessageId and from.address client-side filters during search', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            expect($query)->toHaveKey('$search');

            return [
                'value' => [
                    [
                        'id' => '1',
                        'subject' => 'Invoice 1001',
                        'internetMessageId' => '<id-1@example.com>',
                        'from' => ['emailAddress' => ['address' => 'sender-a@example.com']],
                    ],
                    [
                        'id' => '2',
                        'subject' => 'Invoice 1002',
                        'internetMessageId' => '<id-2@example.com>',
                        'from' => ['emailAddress' => ['address' => 'sender-b@example.com']],
                    ],
                ],
            ];
        }
    };

    $query = new MsGraphMessageQuery($client, new BatchRequest($client), 'invoices@example.com');

    $result = $query
        ->search('invoice')
        ->where('internetMessageId', 'eq', '<id-1@example.com>')
        ->where('from.address', 'contains', 'sender-a')
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

it('retries failed bulk batch items individually', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, string> */
        public array $retriedPatches = [];

        public function __construct() {}

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            if ($endpoint === '/$batch') {
                return [
                    'responses' => [
                        ['id' => '1', 'status' => 200],
                        ['id' => '2', 'status' => 400],
                    ],
                ];
            }

            return [];
        }

        public function patch(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            $this->retriedPatches[] = $endpoint;

            return [];
        }

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return ['value' => []];
        }
    };

    $query = new MsGraphMessageQuery($client, new BatchRequest($client), 'invoices@example.com');

    $query->markAsRead(['m-1', 'm-2']);

    expect($client->retriedPatches)->toHaveCount(1);
    expect($client->retriedPatches[0])->toContain('/users/invoices%40example.com/messages/m-2');
});

it('queries across all folders when allFolders is used', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, string> */
        public array $endpoints = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->endpoints[] = $endpoint;

            return [
                'value' => [
                    ['id' => '1', 'subject' => 'A'],
                ],
            ];
        }
    };

    $query = new MsGraphMessageQuery($client, new BatchRequest($client), 'invoices@example.com');
    $query->allFolders()->get();

    expect($client->endpoints)->toHaveCount(1);
    expect($client->endpoints[0])->toBe('users/invoices%40example.com/messages');
});

it('builds grouped OR filters when whereAny is used', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $queries = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->queries[] = $query;

            return ['value' => []];
        }
    };

    $query = new MsGraphMessageQuery($client, new BatchRequest($client), 'invoices@example.com');
    $query
        ->whereAny('from.address', 'eq', ['a@example.com', 'b@example.com'])
        ->where('isRead', false)
        ->get();

    expect((string) ($client->queries[0]['$filter'] ?? ''))
        ->toContain("(from/emailAddress/address eq 'a@example.com' or from/emailAddress/address eq 'b@example.com')")
        ->toContain('isRead eq false');
});

it('stops on repeated nextLink pages and deduplicates message ids', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, string> */
        public array $endpoints = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->endpoints[] = $endpoint;

            if (count($this->endpoints) === 1) {
                return [
                    'value' => [
                        ['id' => 'm-1', 'subject' => 'A'],
                        ['id' => 'm-2', 'subject' => 'B'],
                    ],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/invoices%40example.com/messages?$skiptoken=repeat',
                ];
            }

            return [
                'value' => [
                    ['id' => 'm-2', 'subject' => 'B'],
                    ['id' => 'm-3', 'subject' => 'C'],
                ],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/invoices%40example.com/messages?$skiptoken=repeat',
            ];
        }
    };

    $query = new MsGraphMessageQuery($client, new BatchRequest($client), 'invoices@example.com');
    $results = $query->allFolders()->get();

    expect($client->endpoints)->toHaveCount(2);
    expect($results->pluck('id')->all())->toBe(['m-1', 'm-2', 'm-3']);
});
