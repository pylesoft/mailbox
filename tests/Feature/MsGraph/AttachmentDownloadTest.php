<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphAttachmentResource;

it('downloads and dedups attachments', function (): void {
    Storage::fake('local');

    config()->set('mailbox.attachment_disk', 'local');
    config()->set('mailbox.attachment_path', 'mailbox-attachments');

    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return [
                'id' => 'att1',
                'name' => 'invoice.pdf',
                'contentType' => 'application/pdf',
                'size' => 4,
                'isInline' => false,
                'contentId' => null,
                'contentBytes' => base64_encode('test'),
            ];
        }
    };

    $resource = new MsGraphAttachmentResource($client, 'invoices@example.com', 'msg1', 'att1');

    $first = $resource->download();
    $second = $resource->download();

    Storage::disk('local')->assertExists($first->path);
    expect($first->alreadyExisted)->toBeFalse();
    expect($second->alreadyExisted)->toBeTrue();
});

it('writes a hash-suffixed path when existing path has different content', function (): void {
    Storage::fake('local');

    config()->set('mailbox.attachment_disk', 'local');
    config()->set('mailbox.attachment_path', 'mailbox-attachments');

    $client = new class extends GraphClient
    {
        private int $calls = 0;

        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            $this->calls++;

            $payload = $this->calls === 1 ? 'first-content' : 'second-content';

            return [
                'id' => 'att1',
                'name' => 'invoice.pdf',
                'contentType' => 'application/pdf',
                'size' => strlen($payload),
                'isInline' => false,
                'contentId' => null,
                'contentBytes' => base64_encode($payload),
            ];
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
