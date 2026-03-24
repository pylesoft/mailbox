<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Services\Folders\FolderLookupService;
use Pyle\Mailbox\Services\Persistence\MessageMoveService;
use Pyle\Mailbox\Services\Persistence\MessageSyncRequest;
use Pyle\Mailbox\Services\Persistence\MessageSyncService;

it('delegates sync and move APIs through the facade manager', function (): void {
    $mailbox = new Mailbox([
        'mailbox_connection_id' => 1,
        'email_address' => 'service@example.com',
        'is_active' => true,
    ]);

    $message = new MailboxMessage([
        'mailbox_id' => 1,
        'provider_message_id' => 'provider-id',
        'canonical_message_key' => 'provider:provider-id',
        'subject' => 'Subject',
        'is_read' => false,
        'is_draft' => false,
        'has_attachments' => false,
        'importance' => 'normal',
    ]);

    $syncResult = collect([$message]);
    $syncService = new RecordingMessageSyncService($syncResult);
    $moveService = new RecordingMessageMoveService($message);

    app()->instance(MessageSyncService::class, $syncService);
    app()->instance(MessageMoveService::class, $moveService);

    $request = new MessageSyncRequest(filters: ['limit' => 10]);

    $syncedMessages = MailboxFacade::syncMailbox($mailbox, $request);
    $movedMessage = MailboxFacade::moveMessage($message, WellKnownFolder::ARCHIVE);

    expect($syncedMessages)->toBeInstanceOf(Collection::class);
    expect($syncedMessages)->toHaveCount(1);
    expect($syncedMessages->first())->toBe($message);
    expect($syncService->calls)->toHaveCount(1);
    expect($syncService->calls[0]['mailbox'])->toBe($mailbox);
    expect($syncService->calls[0]['request'])->toBe($request);
    expect($movedMessage)->toBeInstanceOf(MailboxMessage::class);
    expect($movedMessage)->toBe($message);
    expect($moveService->calls)->toHaveCount(1);
    expect($moveService->calls[0]['message'])->toBe($message);
    expect($moveService->calls[0]['destination'])->toBe(WellKnownFolder::ARCHIVE);
});

it('delegates folder lookup APIs through the facade manager', function (): void {
    $mailbox = new Mailbox([
        'mailbox_connection_id' => 1,
        'email_address' => 'folders@example.com',
        'is_active' => true,
    ]);

    $tree = collect([
        [
            'id' => 'inbox',
            'display_name' => 'Inbox',
            'path' => 'Inbox',
            'parent_id' => null,
            'child_folder_count' => 1,
        ],
    ]);
    $found = [
        'id' => 'processed',
        'display_name' => 'Processed',
        'path' => 'Inbox/Processed',
        'parent_id' => 'inbox',
        'child_folder_count' => 0,
    ];

    $service = new RecordingFolderLookupService($tree, $found);

    app()->instance(FolderLookupService::class, $service);

    $folderTree = MailboxFacade::listFolderTree($mailbox, 5);
    $foundFolder = MailboxFacade::findFolderByName($mailbox, 'Processed', WellKnownFolder::INBOX, false);

    expect($folderTree)->toBeInstanceOf(Collection::class);
    expect($folderTree)->toHaveCount(1);
    expect($folderTree->first()['display_name'])->toBe('Inbox');
    expect($service->listTreeCalls)->toBe([
        [
            'mailbox' => $mailbox,
            'max_depth' => 5,
        ],
    ]);
    expect($foundFolder)->toBe($found);
    expect($foundFolder['path'])->toBe('Inbox/Processed');
    expect($service->findByNameCalls)->toBe([
        [
            'mailbox' => $mailbox,
            'folder_name' => 'Processed',
            'root' => WellKnownFolder::INBOX,
            'case_sensitive' => false,
        ],
    ]);
});

final class RecordingMessageSyncService extends MessageSyncService
{
    /** @var array<int, array{mailbox: Mailbox, request: MessageSyncRequest|array<string, mixed>|null}> */
    public array $calls = [];

    /**
     * @param  Collection<int, MailboxMessage>  $result
     */
    public function __construct(
        private readonly Collection $result,
    ) {}

    public function syncMailbox(Mailbox $mailbox, MessageSyncRequest|array|null $request = null): Collection
    {
        $this->calls[] = [
            'mailbox' => $mailbox,
            'request' => $request,
        ];

        return $this->result;
    }
}

final class RecordingMessageMoveService extends MessageMoveService
{
    /** @var array<int, array{message: MailboxMessage, destination: string|WellKnownFolder}> */
    public array $calls = [];

    public function __construct(
        private readonly MailboxMessage $result,
    ) {}

    public function move(MailboxMessage $message, string|WellKnownFolder $destinationFolder): MailboxMessage
    {
        $this->calls[] = [
            'message' => $message,
            'destination' => $destinationFolder,
        ];

        return $this->result;
    }
}

final class RecordingFolderLookupService extends FolderLookupService
{
    /** @var array<int, array{mailbox: Mailbox, max_depth: int}> */
    public array $listTreeCalls = [];

    /** @var array<int, array{mailbox: Mailbox, folder_name: string, root: string|WellKnownFolder|null, case_sensitive: bool}> */
    public array $findByNameCalls = [];

    /**
     * @param  Collection<int, array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}>  $tree
     * @param  array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}|null  $found
     */
    public function __construct(
        private readonly Collection $tree,
        private readonly ?array $found,
    ) {}

    public function listTree(Mailbox $mailbox, int $maxDepth = 10): Collection
    {
        $this->listTreeCalls[] = [
            'mailbox' => $mailbox,
            'max_depth' => $maxDepth,
        ];

        return $this->tree;
    }

    public function findByName(
        Mailbox $mailbox,
        string $folderName,
        string|WellKnownFolder|null $root = null,
        bool $caseSensitive = true,
    ): ?array {
        $this->findByNameCalls[] = [
            'mailbox' => $mailbox,
            'folder_name' => $folderName,
            'root' => $root,
            'case_sensitive' => $caseSensitive,
        ];

        return $this->found;
    }
}
