<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaCollector;

it('collects created, updated, and deleted graph delta changes across pages', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, array{endpoint:string, mailbox:?string}> */
        public array $requests = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->requests[] = [
                'endpoint' => $endpoint,
                'mailbox' => $mailbox,
            ];

            if (count($this->requests) === 1) {
                return [
                    'value' => [
                        array_replace_recursive(msgraphMessageFixture(), [
                            'id' => 'created-1',
                            'subject' => 'Created',
                        ]),
                        array_replace_recursive(msgraphMessageFixture(), [
                            'id' => 'updated-1',
                            'subject' => 'Updated',
                            'lastModifiedDateTime' => '2026-01-02T00:00:00Z',
                        ]),
                        ['id' => 'deleted-1', '@removed' => ['reason' => 'deleted']],
                    ],
                    '@odata.nextLink' => 'https://graph.example.test/next-page',
                ];
            }

            return [
                'value' => [
                    array_replace_recursive(msgraphMessageFixture(), [
                        'id' => 'updated-2',
                        'subject' => 'Updated Again',
                        '@odata.etag' => 'W/"etag"',
                    ]),
                ],
                '@odata.deltaLink' => 'delta-final',
            ];
        }
    };

    $result = (new MsGraphDeltaCollector($client))->collect('team@example.com', 'archive/2026', null);

    expect($client->requests[0])->toBe([
        'endpoint' => 'users/team%40example.com/mailFolders/archive%2F2026/messages/delta',
        'mailbox' => 'team@example.com',
    ]);
    expect($client->requests[1])->toBe([
        'endpoint' => 'https://graph.example.test/next-page',
        'mailbox' => 'team@example.com',
    ]);
    expect($result->created->pluck('id')->all())->toBe(['created-1']);
    expect($result->updated->pluck('id')->all())->toBe(['updated-1', 'updated-2']);
    expect($result->deleted->all())->toBe(['deleted-1']);
    expect($result->deltaLink)->toBe('delta-final');
    expect($result->fullSyncRequired)->toBeFalse();
});

it('uses the existing delta token as the initial request endpoint', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, string> */
        public array $endpoints = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->endpoints[] = $endpoint;

            return [
                'value' => [],
                '@odata.deltaLink' => 'next-delta-token',
            ];
        }
    };

    $result = (new MsGraphDeltaCollector($client))->collect(
        mailbox: 'team@example.com',
        folderId: 'archive',
        deltaToken: 'https://graph.example.test/delta-token',
    );

    expect($client->endpoints)->toBe(['https://graph.example.test/delta-token']);
    expect($result->deltaLink)->toBe('next-delta-token');
});
