<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailMessageFilterer;
use Pyle\Mailbox\Drivers\Gmail\GmailMessagePageCollector;

it('hydrates gmail pages, applies filters, deduplicates ids, and stops on repeated page tokens', function (): void {
    $client = new class extends GmailClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $listQueries = [];

        /** @var array<int, string> */
        public array $detailRequests = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_ends_with($endpoint, '/messages')) {
                $this->listQueries[] = $query;

                if (! isset($query['pageToken'])) {
                    return [
                        'messages' => [
                            ['id' => 'm-1'],
                            ['id' => 'm-2'],
                        ],
                        'nextPageToken' => 'repeat-token',
                    ];
                }

                return [
                    'messages' => [
                        ['id' => 'm-2'],
                        ['id' => 'm-3'],
                    ],
                    'nextPageToken' => 'repeat-token',
                ];
            }

            $this->detailRequests[] = $endpoint;
            $id = basename($endpoint);

            return gmailMessageFixture([
                'id' => $id,
                'payload' => [
                    'headers' => [
                        ['name' => 'Subject', 'value' => $id === 'm-1' ? 'Skip me' : 'Keep '.$id],
                        ['name' => 'From', 'value' => 'Vendor <vendor@example.com>'],
                        ['name' => 'To', 'value' => 'Finance <finance@example.com>'],
                        ['name' => 'Date', 'value' => 'Wed, 01 Jan 2026 12:00:00 +0000'],
                        ['name' => 'Message-ID', 'value' => sprintf('<%s@example.com>', $id)],
                    ],
                    'parts' => [],
                ],
                'labelIds' => ['INBOX'],
            ]);
        }
    };

    $collector = new GmailMessagePageCollector($client, 'invoices@example.com');
    $filterer = new GmailMessageFilterer([
        [
            'type' => 'single',
            'field' => 'subject',
            'operator' => 'contains',
            'value' => 'keep',
        ],
    ]);

    $result = $collector->collect(
        query: ['maxResults' => 2],
        maxPages: 10,
        limit: 2,
        filterer: $filterer,
    );

    expect($client->listQueries)->toHaveCount(2);
    expect($client->listQueries[0])->not->toHaveKey('pageToken');
    expect($client->listQueries[1]['pageToken'] ?? null)->toBe('repeat-token');
    expect($client->detailRequests)->toHaveCount(3);
    expect($result->pluck('id')->all())->toBe(['m-2', 'm-3']);
});

it('stops when the configured max page count is reached', function (): void {
    $client = new class extends GmailClient
    {
        public int $listCalls = 0;

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_ends_with($endpoint, '/messages')) {
                $this->listCalls++;

                return [
                    'messages' => [
                        ['id' => 'm-'.$this->listCalls],
                    ],
                    'nextPageToken' => 'page-'.$this->listCalls,
                ];
            }

            return gmailMessageFixture([
                'id' => basename($endpoint),
                'payload' => [
                    'headers' => [
                        ['name' => 'Subject', 'value' => basename($endpoint)],
                    ],
                    'parts' => [],
                ],
                'labelIds' => ['INBOX'],
            ]);
        }
    };

    $result = (new GmailMessagePageCollector($client, 'invoices@example.com'))->collect(
        query: ['maxResults' => 5],
        maxPages: 1,
    );

    expect($client->listCalls)->toBe(1);
    expect($result->pluck('id')->all())->toBe(['m-1']);
});
