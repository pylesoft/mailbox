<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaSync;
use Pyle\Mailbox\Exceptions\ApiRequestException;

it('performs initial delta sync', function (): void {
    $client = new class extends GraphClient {
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
    $client = new class extends GraphClient {
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
