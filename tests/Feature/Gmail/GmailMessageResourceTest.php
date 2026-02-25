<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailMessageResource;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\MailboxException;

it('performs gmail message operations', function (): void {
    $client = new class extends GmailClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $posts = [];

        /** @var array<int, string> */
        public array $deletes = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (($query['format'] ?? null) === 'raw') {
                return ['raw' => 'raw-message'];
            }

            return gmailMessageFixture([
                'id' => 'msg-1',
                'labelIds' => ['INBOX'],
                'payload' => [
                    'headers' => [
                        ['name' => 'Subject', 'value' => 'Test Subject'],
                        ['name' => 'From', 'value' => 'Vendor <vendor@example.com>'],
                    ],
                    'parts' => [],
                ],
            ]);
        }

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            $this->posts[] = ['endpoint' => $endpoint, 'payload' => $payload];

            if (str_ends_with($endpoint, '/import')) {
                return ['id' => 'copied-msg'];
            }

            return [];
        }

        public function delete(string $endpoint, ?string $mailbox = null): void
        {
            $this->deletes[] = $endpoint;
        }
    };

    $resource = new GmailMessageResource($client, 'invoices@example.com', 'msg-1');

    $resource->markAsRead();
    $resource->markAsUnread();
    $resource->moveTo(WellKnownFolder::ARCHIVE);
    $copied = $resource->copyTo('Processed');
    $resource->delete();

    expect($client->posts)->toHaveCount(4);
    expect($client->deletes)->toHaveCount(1);
    expect($copied->id)->toBe('msg-1');
});

it('throws when gmail copy raw payload is unavailable', function (): void {
    $client = new class extends GmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return ($query['format'] ?? null) === 'raw'
                ? []
                : gmailMessageFixture();
        }

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            return [];
        }
    };

    $resource = new GmailMessageResource($client, 'invoices@example.com', 'msg-1');

    $resource->copyTo('Processed');
})->throws(MailboxException::class);
