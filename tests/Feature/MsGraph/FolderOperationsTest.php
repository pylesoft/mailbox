<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphFolderQuery;

it('builds folder tree', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, 'childFolders')) {
                return ['value' => []];
            }

            return [
                'value' => [
                    ['id' => 'inbox', 'displayName' => 'Inbox', 'childFolderCount' => 0, 'totalItemCount' => 1, 'unreadItemCount' => 0],
                ],
            ];
        }

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            return ['id' => 'new-folder', 'displayName' => $payload['displayName'], 'childFolderCount' => 0, 'totalItemCount' => 0, 'unreadItemCount' => 0];
        }
    };

    $query = new MsGraphFolderQuery($client, 'invoices@example.com');

    expect($query->tree())->toHaveCount(1);
    expect($query->create('Processed')->displayName)->toBe('Processed');
});
