<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaSync;
use Pyle\Mailbox\Exceptions\ApiRequestException;

it('performs initial delta sync', function (): void {
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
                '@odata.deltaLink' => 'delta-token',
            ];
        }
    };

    $sync = new MsGraphDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'inbox');

    expect($result->created)->toHaveCount(2);
    expect($result->deltaLink)->toBe('delta-token');
    expect($result->fullSyncRequired)->toBeFalse();
});

it('handles expired delta token', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            throw new ApiRequestException('Gone', status: 410);
        }
    };

    $sync = new MsGraphDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'inbox', 'stale-token');

    expect($result->fullSyncRequired)->toBeTrue();
    expect($result->deltaLink)->toBeNull();
});

it('handles incremental delta pages with updated and deleted messages', function (): void {
    $client = new class extends GraphClient
    {
        private int $calls = 0;

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->calls++;

            if ($this->calls === 1) {
                return [
                    'value' => [
                        ['id' => '100', 'subject' => 'Updated', 'lastModifiedDateTime' => '2026-01-01T00:00:00Z'],
                        ['id' => '200', '@removed' => ['reason' => 'deleted']],
                    ],
                    '@odata.nextLink' => 'next-page-token',
                ];
            }

            return [
                'value' => [],
                '@odata.deltaLink' => 'final-delta-token',
            ];
        }
    };

    $sync = new MsGraphDeltaSync($client);
    $result = $sync->syncFolder('test@example.com', 'inbox', 'existing-delta');

    expect($result->updated)->toHaveCount(1);
    expect($result->deleted)->toHaveCount(1);
    expect($result->deleted->first())->toBe('200');
    expect($result->deltaLink)->toBe('final-delta-token');
    expect($result->fullSyncRequired)->toBeFalse();
});
