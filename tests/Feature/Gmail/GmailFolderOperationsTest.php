<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailFolderQuery;

it('builds gmail folder tree and creates labels', function (): void {
    $client = new class extends GmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return [
                'labels' => [
                    ['id' => 'INBOX', 'name' => 'INBOX', 'messagesTotal' => 2, 'messagesUnread' => 1],
                    ['id' => 'Finance', 'name' => 'Finance', 'messagesTotal' => 0, 'messagesUnread' => 0],
                    ['id' => 'Finance/Processed', 'name' => 'Finance/Processed', 'messagesTotal' => 0, 'messagesUnread' => 0],
                ],
            ];
        }

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            return [
                'id' => (string) ($payload['name'] ?? 'new'),
                'name' => (string) ($payload['name'] ?? 'new'),
                'messagesTotal' => 0,
                'messagesUnread' => 0,
            ];
        }
    };

    $query = new GmailFolderQuery($client, 'invoices@example.com');

    expect($query->tree())->toHaveCount(2);
    expect($query->find('Finance/Processed')?->displayName)->toBe('Finance/Processed');
    expect($query->create('Processed', 'Finance')->displayName)->toBe('Finance/Processed');
    expect($query->createPath('Inbox/Finance/Archive')->displayName)->toBe('Inbox/Finance/Archive');
});
