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
        /** @var array<int, array<string, mixed>> */
        public array $queries = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/attachments')) {
                $this->queries[] = $query;

                return [
                    'value' => [
                        [
                            '@odata.type' => '#microsoft.graph.fileAttachment',
                            'id' => 'a1',
                            'name' => 'invoice.pdf',
                            'contentType' => 'application/pdf',
                            'size' => 100,
                            'isInline' => true,
                            'contentId' => 'image001',
                            'contentBytes' => str_repeat('unwanted-content', 1000),
                        ],
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
    expect($attachments->first()?->contentId)->toBe('image001');
    expect($client->queries)->toBe([
        ['$select' => 'id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId'],
    ]);
});

it('requests attachment metadata without content bytes', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, array{endpoint:string,query:array<string,mixed>}> */
        public array $requests = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->requests[] = ['endpoint' => $endpoint, 'query' => $query];

            return [
                '@odata.type' => '#microsoft.graph.itemAttachment',
                'id' => 'item-1',
                'name' => 'forwarded.eml',
                'contentType' => 'message/rfc822',
                'size' => 100,
                'isInline' => false,
                'contentBytes' => str_repeat('unwanted-content', 1000),
            ];
        }
    };

    $resource = new MsGraphMessageResource($client, 'invoices@example.com', 'msg-1');

    $metadata = $resource->attachment('item-1')->metadata();

    expect($metadata->contentType)->toBe('message/rfc822');
    expect($client->requests)->toBe([
        [
            'endpoint' => 'users/invoices%40example.com/messages/msg-1/attachments/item-1',
            'query' => ['$select' => 'id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId'],
        ],
    ]);
});
