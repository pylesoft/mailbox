<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaCollector;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;

it('collects created, updated, and deleted graph delta changes across pages', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, array{endpoint:string, mailbox:?string}> */
        public array $requests = [];

        public function __construct()
        {
            parent::__construct([], new TokenManager([]), new RateLimiter([]));
        }

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
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive%2F2026/messages/delta?$skiptoken=next-page',
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
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive%2F2026/messages/delta?$deltatoken=final',
            ];
        }
    };

    $result = (new MsGraphDeltaCollector($client))->collect('team@example.com', 'archive/2026', null);

    expect($client->requests[0])->toBe([
        'endpoint' => 'users/team%40example.com/mailFolders/archive%2F2026/messages/delta',
        'mailbox' => 'team@example.com',
    ]);
    expect($client->requests[1])->toBe([
        'endpoint' => 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive%2F2026/messages/delta?$skiptoken=next-page',
        'mailbox' => 'team@example.com',
    ]);
    expect($result->created->pluck('id')->all())->toBe(['created-1']);
    expect($result->updated->pluck('id')->all())->toBe(['updated-1', 'updated-2']);
    expect($result->deleted->all())->toBe(['deleted-1']);
    expect($result->deltaLink)->toBe('https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive%2F2026/messages/delta?$deltatoken=final');
    expect($result->fullSyncRequired)->toBeFalse();
});

it('uses the existing delta token as the initial request endpoint', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, string> */
        public array $endpoints = [];

        public function __construct()
        {
            parent::__construct([], new TokenManager([]), new RateLimiter([]));
        }

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->endpoints[] = $endpoint;

            return [
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive/messages/delta?$deltatoken=next',
            ];
        }
    };

    $result = (new MsGraphDeltaCollector($client))->collect(
        mailbox: 'team@example.com',
        folderId: 'archive',
        deltaToken: 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive/messages/delta?$deltatoken=existing',
    );

    expect($client->endpoints)->toBe(['https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive/messages/delta?$deltatoken=existing']);
    expect($result->deltaLink)->toBe('https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive/messages/delta?$deltatoken=next');
});
