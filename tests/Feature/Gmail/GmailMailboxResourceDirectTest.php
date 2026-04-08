<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailDeltaSync;
use Pyle\Mailbox\Drivers\Gmail\GmailFolderResource;
use Pyle\Mailbox\Drivers\Gmail\GmailLabelResolver;
use Pyle\Mailbox\Drivers\Gmail\GmailMailboxResource;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\MailboxException;

it('resolves gmail labels and builds folder resources directly', function (): void {
    $client = new class extends GmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return match (true) {
                str_contains($endpoint, '/labels/INBOX') => [
                    'id' => 'INBOX',
                    'name' => 'Inbox',
                    'messagesTotal' => 10,
                    'messagesUnread' => 2,
                ],
                str_ends_with($endpoint, '/labels') => [
                    'labels' => [
                        ['id' => 'INBOX', 'name' => 'Inbox', 'messagesTotal' => 10, 'messagesUnread' => 2],
                        ['id' => 'Inbox/Processed', 'name' => 'Inbox/Processed', 'messagesTotal' => 2, 'messagesUnread' => 0],
                    ],
                ],
                default => gmailMessageFixture(),
            };
        }
    };

    $deltaSync = new class($client) extends GmailDeltaSync
    {
        public function __construct(GmailClient $client)
        {
            parent::__construct($client);
        }

        public function syncFolder(string $mailbox, string $folderId, ?string $deltaToken = null): DeltaResultDto
        {
            return new DeltaResultDto(
                created: collect(),
                updated: collect(),
                deleted: collect(),
                deltaLink: 'gmail-delta-token',
                fullSyncRequired: false,
            );
        }
    };

    expect(GmailLabelResolver::resolve(WellKnownFolder::INBOX))->toBe('INBOX');
    expect(GmailLabelResolver::resolve(' Projects/Done '))->toBe('Projects/Done');

    $resource = new GmailMailboxResource($client, $deltaSync, 'folders@example.com');
    $folderResource = $resource->folder(WellKnownFolder::INBOX);

    expect($folderResource)->toBeInstanceOf(GmailFolderResource::class);
    expect($folderResource->get()->displayName)->toBe('Inbox');
    expect($folderResource->children()->pluck('displayName')->all())->toBe(['Inbox/Processed']);
    expect($folderResource->delta('history-1')->deltaLink)->toBe('gmail-delta-token');
});

it('throws when a gmail folder move is attempted', function (): void {
    $client = new class extends GmailClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return [
                'id' => 'INBOX',
                'name' => 'Inbox',
                'messagesTotal' => 10,
                'messagesUnread' => 2,
            ];
        }
    };

    $deltaSync = new class($client) extends GmailDeltaSync
    {
        public function __construct(GmailClient $client)
        {
            parent::__construct($client);
        }
    };

    $resource = new GmailFolderResource($client, $deltaSync, 'folders@example.com', 'INBOX');

    expect(fn (): mixed => $resource->moveTo('archive'))
        ->toThrow(MailboxException::class, 'Gmail labels do not support folder-parent moves');
});
