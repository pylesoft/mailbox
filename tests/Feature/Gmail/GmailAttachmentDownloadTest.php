<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pyle\Mailbox\Drivers\Gmail\GmailAttachmentResource;
use Pyle\Mailbox\Tests\Support\FakeGmailClient;

it('downloads and dedups gmail attachments', function (): void {
    Storage::fake('local');

    config()->set('mailbox.attachment_disk', 'local');
    config()->set('mailbox.attachment_path', 'mailbox-attachments');

    $client = new class extends FakeGmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            if (str_contains($endpoint, '/attachments/')) {
                return [
                    'data' => rtrim(strtr(base64_encode('test'), '+/', '-_'), '='),
                ];
            }

            return gmailMessageFixture([
                'id' => 'msg1',
                'payload' => [
                    'parts' => [
                        [
                            'partId' => '1',
                            'filename' => 'invoice.pdf',
                            'mimeType' => 'application/pdf',
                            'body' => [
                                'data' => '',
                                'attachmentId' => 'att1',
                                'size' => 4,
                            ],
                        ],
                    ],
                ],
            ]);
        }
    };

    $resource = new GmailAttachmentResource($client, 'invoices@example.com', 'msg1', 'att1');

    $first = $resource->download();
    $second = $resource->download();

    Storage::disk('local')->assertExists($first->path);
    expect($first->alreadyExisted)->toBeFalse();
    expect($second->alreadyExisted)->toBeTrue();
    expect(Storage::disk('local')->get($first->path))->toBe('test');
    expect($first->size)->toBe(4);
});
