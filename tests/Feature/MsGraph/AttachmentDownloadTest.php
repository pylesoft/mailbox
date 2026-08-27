<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphAttachmentResource;

it('downloads and dedups attachments', function (): void {
    Storage::fake('local');

    config()->set('mailbox.attachment_disk', 'local');
    config()->set('mailbox.attachment_path', 'mailbox-attachments');

    $client = new class extends GraphClient
    {
        /** @var array<int, string> */
        public array $streams = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return [
                'id' => 'att1',
                'name' => 'invoice.pdf',
                'contentType' => 'application/pdf',
                'size' => 4,
                'isInline' => false,
                'contentId' => 'cid-test',
            ];
        }

        public function stream(string $endpoint, ?string $mailbox = null): StreamInterface
        {
            $this->streams[] = $endpoint;

            return Utils::streamFor('test');
        }
    };

    $resource = new MsGraphAttachmentResource($client, 'invoices@example.com', 'msg1', 'att1');

    $first = $resource->download();
    $second = $resource->download();

    Storage::disk('local')->assertExists($first->path);
    expect($first->alreadyExisted)->toBeFalse();
    expect($second->alreadyExisted)->toBeTrue();
    expect($client->streams)->toBe([
        'users/invoices%40example.com/messages/msg1/attachments/att1/$value',
        'users/invoices%40example.com/messages/msg1/attachments/att1/$value',
    ]);
    expect($first->contentId)->toBe('cid-test');
});

it('writes a hash-suffixed path when existing path has different content', function (): void {
    Storage::fake('local');

    config()->set('mailbox.attachment_disk', 'local');
    config()->set('mailbox.attachment_path', 'mailbox-attachments');

    $client = new class extends GraphClient
    {
        private int $calls = 0;

        /** @var array<int, string> */
        public array $streams = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->calls++;

            $payload = $this->calls === 1 ? 'first-metadata' : 'second-metadata';

            return [
                'id' => 'att1',
                'name' => 'invoice.pdf',
                'contentType' => 'application/pdf',
                'size' => strlen($payload),
                'isInline' => false,
                'contentId' => null,
            ];
        }

        public function stream(string $endpoint, ?string $mailbox = null): StreamInterface
        {
            $this->streams[] = $endpoint;

            return Utils::streamFor($this->calls === 1 ? 'first-content' : 'second-content');
        }
    };

    $resource = new MsGraphAttachmentResource($client, 'invoices@example.com', 'msg1', 'att1');

    $first = $resource->download();
    $second = $resource->download();
    $third = $resource->download();

    expect($first->alreadyExisted)->toBeFalse();
    expect($second->alreadyExisted)->toBeFalse();
    expect($third->alreadyExisted)->toBeTrue();
    expect($second->path)->not->toBe($first->path);
    expect($second->path)->toContain('-');

    Storage::disk('local')->assertExists($first->path);
    Storage::disk('local')->assertExists($second->path);
});

it('streams file attachment bytes without reading contentBytes', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            throw new RuntimeException('Metadata must not be requested while streaming.');
        }

        public function stream(string $endpoint, ?string $mailbox = null): StreamInterface
        {
            expect($endpoint)->toBe('users/invoices%40example.com/messages/msg1/attachments/att1/$value');

            return Utils::streamFor('raw-file-bytes');
        }
    };

    $resource = new MsGraphAttachmentResource($client, 'invoices@example.com', 'msg1', 'att1');

    expect((string) $resource->stream())->toBe('raw-file-bytes');
});

it('streams item attachments as raw MIME content', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return [
                '@odata.type' => '#microsoft.graph.itemAttachment',
                'id' => 'item-1',
                'name' => 'forwarded.eml',
                'contentType' => 'message/rfc822',
                'size' => 32,
                'isInline' => false,
                'contentBytes' => 'must-not-be-used',
            ];
        }

        public function stream(string $endpoint, ?string $mailbox = null): StreamInterface
        {
            return Utils::streamFor("MIME-Version: 1.0\r\n\r\nForwarded message");
        }
    };

    $resource = new MsGraphAttachmentResource($client, 'invoices@example.com', 'msg1', 'item-1');

    expect((string) $resource->stream())->toBe("MIME-Version: 1.0\r\n\r\nForwarded message");
});
