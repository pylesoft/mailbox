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
