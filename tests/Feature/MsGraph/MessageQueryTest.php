<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\BatchRequest;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMessageQuery;

it('queries messages and maps DTOs', function (): void {
    $client = new class extends GraphClient {
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
