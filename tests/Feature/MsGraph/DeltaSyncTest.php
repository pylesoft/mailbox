<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaSync;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\Exceptions\ApiRequestException;

it('performs initial delta sync', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct()
        {
            parent::__construct([], new TokenManager([]), new RateLimiter([]));
        }

        public function getMessagePayload(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return [
                'value' => [
                    ['id' => '1', 'subject' => 'A'],
                    ['id' => '2', 'subject' => 'B'],
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/test%40example.com/mailFolders/inbox/messages/delta?$deltatoken=initial',
            ];
        }
    };

    $sync = new MsGraphDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'inbox');

    expect($result->created)->toHaveCount(2);
    expect($result->deltaLink)->toBe('https://graph.microsoft.com/v1.0/users/test%40example.com/mailFolders/inbox/messages/delta?$deltatoken=initial');
    expect($result->fullSyncRequired)->toBeFalse();
});

it('handles expired delta token', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct()
        {
            parent::__construct([], new TokenManager([]), new RateLimiter([]));
        }

        public function getMessagePayload(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            throw new ApiRequestException('Gone', status: 410);
        }
    };

    $sync = new MsGraphDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'inbox', 'https://graph.microsoft.com/v1.0/users/test%40example.com/mailFolders/inbox/messages/delta?$deltatoken=stale');

    expect($result->fullSyncRequired)->toBeTrue();
    expect($result->deltaLink)->toBeNull();
});

it('handles incremental delta pages with updated and deleted messages', function (): void {
    $client = new class extends GraphClient
    {
        private int $calls = 0;

        public function __construct()
        {
            parent::__construct([], new TokenManager([]), new RateLimiter([]));
        }

        public function getMessagePayload(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->calls++;

            if ($this->calls === 1) {
                return [
                    'value' => [
                        ['id' => '100', 'subject' => 'Updated', 'lastModifiedDateTime' => '2026-01-01T00:00:00Z'],
                        ['id' => '200', '@removed' => ['reason' => 'deleted']],
                    ],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/test%40example.com/mailFolders/inbox/messages/delta?$skiptoken=next',
                ];
            }

            return [
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/test%40example.com/mailFolders/inbox/messages/delta?$deltatoken=final',
            ];
        }
    };

    $sync = new MsGraphDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'inbox', 'https://graph.microsoft.com/v1.0/users/test%40example.com/mailFolders/inbox/messages/delta?$deltatoken=existing');

    expect($result->updated)->toHaveCount(1);
    expect($result->deleted)->toHaveCount(1);
    expect($result->deleted->first())->toBe('200');
    expect($result->deltaLink)->toBe('https://graph.microsoft.com/v1.0/users/test%40example.com/mailFolders/inbox/messages/delta?$deltatoken=final');
    expect($result->fullSyncRequired)->toBeFalse();
});
