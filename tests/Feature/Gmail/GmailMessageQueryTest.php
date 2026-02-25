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
    expect((array) $client->batchPayloads[0]['ids'])->toHaveCount(1000);
    expect((array) $client->batchPayloads[1]['ids'])->toHaveCount(1000);
    expect((array) $client->batchPayloads[2]['ids'])->toHaveCount(205);
});
