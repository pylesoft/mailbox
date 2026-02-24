<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMessageResource;
use Pyle\Mailbox\Enums\WellKnownFolder;

it('performs message read and move operations', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $patches = [];

        /** @var array<int, array<string, mixed>> */
        public array $posts = [];

        /** @var array<int, string> */
        public array $deletes = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return msgraphMessageFixture();
        }

        public function patch(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            $this->patches[] = ['endpoint' => $endpoint, 'payload' => $payload, 'mailbox' => $mailbox];

            return [];
        }

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            $this->posts[] = ['endpoint' => $endpoint, 'payload' => $payload, 'mailbox' => $mailbox];

            $response = msgraphMessageFixture();
            $response['parentFolderId'] = (string) ($payload['destinationId'] ?? '');

            return $response;
        }

        public function delete(string $endpoint, ?string $mailbox = null): void
        {
            $this->deletes[] = $endpoint;
        }
    };

    $resource = new MsGraphMessageResource($client, 'invoices@example.com', 'msg-1');

    $resource->markAsRead();
    $resource->markAsUnread();
    $moved = $resource->moveTo(WellKnownFolder::ARCHIVE);
    $copied = $resource->copyTo('processed-folder');
    $resource->delete();

    expect($client->patches)->toHaveCount(2);
    expect($client->posts)->toHaveCount(2);
    expect($client->deletes)->toHaveCount(1);
    expect($moved->parentFolderId)->toBe('archive');
    expect($copied->parentFolderId)->toBe('processed-folder');
});

it('returns attachment metadata collection', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/attachments')) {
                return [
                    'value' => [
                        ['id' => 'a1', 'name' => 'invoice.pdf', 'contentType' => 'application/pdf', 'size' => 100, 'isInline' => false],
                    ],
                ];
            }

            return msgraphMessageFixture();
        }
    };

    $resource = new MsGraphMessageResource($client, 'invoices@example.com', 'msg-1');

    $attachments = $resource->attachments();

    expect($attachments)->toHaveCount(1);
    expect($attachments->first()?->name)->toBe('invoice.pdf');
});
