<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailDeltaSync;
use Pyle\Mailbox\Exceptions\ApiRequestException;

it('performs initial gmail delta sync', function (): void {
    $client = new class extends GmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/profile')) {
                return ['historyId' => 'h-200'];
            }

            if (str_ends_with($endpoint, '/messages')) {
                return ['messages' => [['id' => 'm-1']]];
            }

            return gmailMessageFixture(['id' => 'm-1', 'labelIds' => ['INBOX']]);
        }
    };

    $sync = new GmailDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'INBOX');

    expect($result->created)->toHaveCount(1);
    expect($result->deltaLink)->toBe('h-200');
    expect($result->fullSyncRequired)->toBeFalse();
});

it('handles stale gmail history id with full sync requirement', function (): void {
    $client = new class extends GmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/history')) {
                throw new ApiRequestException('historyId out of date', status: 404, endpoint: $endpoint);
            }

            return [];
        }
    };

    $sync = new GmailDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'INBOX', 'old-history');

    expect($result->fullSyncRequired)->toBeTrue();
    expect($result->deltaLink)->toBeNull();
});

it('handles incremental gmail history pages', function (): void {
    $client = new class extends GmailClient
    {
        private int $historyCalls = 0;

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/history')) {
                $this->historyCalls++;

                if ($this->historyCalls === 1) {
                    return [
                        'history' => [
                            [
                                'messagesAdded' => [
                                    ['message' => ['id' => '100']],
                                ],
                                'messagesDeleted' => [
                                    ['message' => ['id' => '200']],
                                ],
                            ],
                        ],
                        'nextPageToken' => 'next',
                        'historyId' => 'h-300',
                    ];
                }

                return [
                    'history' => [],
                    'historyId' => 'h-301',
                ];
            }

            if (str_contains($endpoint, '/messages/100')) {
                return gmailMessageFixture(['id' => '100', 'labelIds' => ['INBOX']]);
            }

            if (str_contains($endpoint, '/profile')) {
                return ['historyId' => 'h-301'];
            }

            return [];
        }
    };

    $sync = new GmailDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'INBOX', 'h-200');

    expect($result->created)->toHaveCount(1);
    expect($result->deleted)->toHaveCount(1);
    expect($result->deleted->first())->toBe('200');
    expect($result->deltaLink)->toBe('h-301');
});
