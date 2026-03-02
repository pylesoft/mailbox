<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailMessageQuery;

it('queries gmail messages and maps DTOs', function (): void {
    $client = new class extends GmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_ends_with($endpoint, '/messages')) {
                return [
                    'messages' => [
                        ['id' => 'm-1'],
                        ['id' => 'm-2'],
                    ],
                ];
            }

            return gmailMessageFixture([
                'id' => basename($endpoint),
                'payload' => ['headers' => [['name' => 'Subject', 'value' => basename($endpoint)]], 'parts' => []],
                'labelIds' => ['INBOX'],
            ]);
        }
    };

    $query = new GmailMessageQuery($client, 'invoices@example.com');

    $result = $query->where('isRead', false)->take(2)->get();

    expect($result)->toHaveCount(2);
    expect($result->first()?->subject)->toBe('m-2');
});

it('combines search and where into gmail q syntax', function (): void {
    $client = new class extends GmailClient
    {
        public array $queries = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_ends_with($endpoint, '/messages')) {
                $this->queries[] = $query;

                return ['messages' => [['id' => 'm-1']]];
            }

            return gmailMessageFixture([
                'id' => 'm-1',
                'labelIds' => ['INBOX', 'UNREAD'],
            ]);
        }
    };

    $query = new GmailMessageQuery($client, 'invoices@example.com');

    $query->search('invoice')->where('isRead', false)->get();

    expect($client->queries)->toHaveCount(1);
    expect((string) ($client->queries[0]['q'] ?? ''))->toContain('invoice');
    expect((string) ($client->queries[0]['q'] ?? ''))->toContain('is:unread');
});

it('applies internetMessageId filter client-side when search is used', function (): void {
    $client = new class extends GmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_ends_with($endpoint, '/messages')) {
                return [
                    'messages' => [
                        ['id' => 'm-1'],
                        ['id' => 'm-2'],
                    ],
                ];
            }

            $id = basename($endpoint);

            return gmailMessageFixture([
                'id' => $id,
                'payload' => [
                    'headers' => [
                        ['name' => 'Subject', 'value' => 'Invoice '.$id],
                        ['name' => 'Message-ID', 'value' => $id === 'm-1' ? '<m-1@example.com>' : '<m-2@example.com>'],
                    ],
                    'parts' => [],
                ],
                'labelIds' => ['INBOX'],
            ]);
        }
    };

    $query = new GmailMessageQuery($client, 'invoices@example.com');
    $result = $query
        ->search('invoice')
        ->where('internetMessageId', 'eq', '<m-1@example.com>')
        ->get();

    expect($result)->toHaveCount(1);
    expect($result->first()?->id)->toBe('m-1');
});

it('sends bulk batch modify requests in chunks', function (): void {
    $client = new class extends GmailClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $batchPayloads = [];

        public function __construct() {}

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/batchModify')) {
                $this->batchPayloads[] = $payload;
            }

            return [];
        }

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return ['messages' => []];
        }
    };

    $query = new GmailMessageQuery($client, 'invoices@example.com');
    $ids = array_map(static fn (int $i): string => 'm-'.$i, range(1, 2205));

    $query->markAsRead($ids);

    expect($client->batchPayloads)->toHaveCount(3);
    expect((array) $client->batchPayloads[0]['addLabelIds'])->toBe([]);
    expect((array) $client->batchPayloads[0]['removeLabelIds'])->toBe(['UNREAD']);
    expect((array) $client->batchPayloads[0]['ids'])->toHaveCount(1000);
    expect((array) $client->batchPayloads[1]['ids'])->toHaveCount(1000);
    expect((array) $client->batchPayloads[2]['ids'])->toHaveCount(205);
});

it('marks gmail messages unread by adding the unread label', function (): void {
    $client = new class extends GmailClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $batchPayloads = [];

        public function __construct() {}

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/batchModify')) {
                $this->batchPayloads[] = $payload;
            }

            return [];
        }

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return ['messages' => []];
        }
    };

    $query = new GmailMessageQuery($client, 'invoices@example.com');
    $query->markAsUnread(['m-1']);

    expect($client->batchPayloads)->toHaveCount(1);
    expect((array) $client->batchPayloads[0]['addLabelIds'])->toBe(['UNREAD']);
    expect((array) $client->batchPayloads[0]['removeLabelIds'])->toBe([]);
});

it('removes source custom label when moving queried gmail messages', function (): void {
    $client = new class extends GmailClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $batchPayloads = [];

        public function __construct() {}

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/batchModify')) {
                $this->batchPayloads[] = $payload;
            }

            return [];
        }

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return ['messages' => []];
        }
    };

    $query = (new GmailMessageQuery($client, 'invoices@example.com'))->inFolder('Finance/Inbox');
    $query->moveTo('Finance/Processed', ['m-1']);

    expect($client->batchPayloads)->toHaveCount(1);
    expect((array) $client->batchPayloads[0]['addLabelIds'])->toBe(['Finance/Processed']);
    expect((array) $client->batchPayloads[0]['removeLabelIds'])->toContain('Finance/Inbox');
});

it('queries across all folders when allFolders is used', function (): void {
    $client = new class extends GmailClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $queries = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_ends_with($endpoint, '/messages')) {
                $this->queries[] = $query;

                return ['messages' => []];
            }

            return gmailMessageFixture();
        }
    };

    $query = new GmailMessageQuery($client, 'invoices@example.com');
    $query->allFolders()->get();

    expect($client->queries)->toHaveCount(1);
    expect($client->queries[0])->not->toHaveKey('labelIds');
});

it('builds grouped OR filters when whereAny is used', function (): void {
    $client = new class extends GmailClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $queries = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_ends_with($endpoint, '/messages')) {
                $this->queries[] = $query;

                return ['messages' => []];
            }

            return gmailMessageFixture();
        }
    };

    $query = new GmailMessageQuery($client, 'invoices@example.com');
    $query
        ->whereAny('from.address', 'contains', ['vendor-a@example.com', 'vendor-b@example.com'])
        ->get();

    expect((string) ($client->queries[0]['q'] ?? ''))->toContain('{from:"vendor-a@example.com" from:"vendor-b@example.com"}');
});

it('stops on repeated page tokens and deduplicates message ids', function (): void {
    $client = new class extends GmailClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $queries = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_ends_with($endpoint, '/messages')) {
                $this->queries[] = $query;

                if (count($this->queries) === 1) {
                    return [
                        'messages' => [
                            ['id' => 'm-1'],
                            ['id' => 'm-2'],
                        ],
                        'nextPageToken' => 'repeat',
                    ];
                }

                return [
                    'messages' => [
                        ['id' => 'm-2'],
                        ['id' => 'm-3'],
                    ],
                    'nextPageToken' => 'repeat',
                ];
            }

            return gmailMessageFixture([
                'id' => basename($endpoint),
                'payload' => ['headers' => [['name' => 'Subject', 'value' => basename($endpoint)]], 'parts' => []],
                'labelIds' => ['INBOX'],
            ]);
        }
    };

    $query = new GmailMessageQuery($client, 'invoices@example.com');
    $result = $query->allFolders()->get();

    expect($client->queries)->toHaveCount(2);
    expect($result->pluck('id')->all())->toBe(['m-3', 'm-2', 'm-1']);
});
