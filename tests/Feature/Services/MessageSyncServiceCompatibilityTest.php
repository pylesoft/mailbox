<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Services\Persistence\MessageSyncRequest;
use Pyle\Mailbox\Services\Persistence\MessageSyncRuleTree;
use Pyle\Mailbox\Services\Persistence\MessageSyncService;

require_once __DIR__.'/Support/MessagePersistenceTestSupport.php';

afterEach(function (): void {
    \Mockery::close();
});

it('resolves backward-compatible folder references for mailbox sync requests', function (array $request, ?string $expectedFolder): void {
    $mailbox = createTestMailbox(
        connectionName: 'Folder Compatibility Connection',
        emailAddress: 'folders@example.com',
        displayName: 'Folder Compatibility Mailbox',
    );

    $query = new FolderTrackingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, $request);

    expect($query->allFoldersCalls)->toBe($expectedFolder === null ? 1 : 0);
    expect($query->resolvedFolder())->toBe($expectedFolder);
})->with([
    'top-level folder' => [[
        'folder' => 'inbox',
        'filters' => ['limit' => 10],
    ], 'inbox'],
    'top-level folder reference' => [[
        'folder_reference' => 'wk:inbox',
        'filters' => ['limit' => 10],
    ], 'inbox'],
    'legacy filters mail folder id' => [[
        'filters' => [
            'mail_folder_id' => 'archive-folder',
            'limit' => 10,
        ],
    ], 'archive-folder'],
    'unknown wk folder ref is stripped' => [[
        'folder_reference' => 'wk:custom-folder',
        'filters' => ['limit' => 10],
    ], 'custom-folder'],
]);

it('normalizes dto legacy payloads the same as array requests', function (): void {
    $legacyRuleTree = [
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
        ],
    ];
    $ruleTree = new MessageSyncRuleTree;

    $arrayRequest = MessageSyncRequest::from([
        'filters' => [
            'rule_tree' => $legacyRuleTree,
            'mail_folder_id' => 'wk:custom-folder',
            'limit' => 10,
        ],
    ], $ruleTree);

    $dtoRequest = MessageSyncRequest::from(new MessageSyncRequest(
        filters: [
            'rule_tree' => $legacyRuleTree,
            'mail_folder_id' => 'wk:custom-folder',
            'limit' => 10,
        ],
    ), $ruleTree);

    expect($dtoRequest->filters())->toBe($arrayRequest->filters());
    expect($dtoRequest->ruleTree())->toBe($arrayRequest->ruleTree());
    expect($dtoRequest->folderReference())->toBe($arrayRequest->folderReference());
    expect($dtoRequest->persistAttachments())->toBe($arrayRequest->persistAttachments());
    expect($dtoRequest->ruleTree())->toBe($legacyRuleTree);
    expect($dtoRequest->folderReference())->toBe('custom-folder');
});

final class FolderTrackingMessageQueryBuilder implements MessageQueryBuilder
{
    /** @var list<string> */
    public array $inFolderCalls = [];

    public int $allFoldersCalls = 0;

    public function inFolder(string|WellKnownFolder $folder): static
    {
        $this->inFolderCalls[] = $folder instanceof WellKnownFolder ? $folder->value : $folder;

        return $this;
    }

    public function allFolders(): static
    {
        $this->allFoldersCalls++;

        return $this;
    }

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static
    {
        return $this;
    }

    public function whereAny(FilterableField|string $field, mixed $operator, array $values): static
    {
        return $this;
    }

    public function search(string $query): static
    {
        return $this;
    }

    public function select(array $fields): static
    {
        return $this;
    }

    public function orderBy(string $field, string $direction = 'desc'): static
    {
        return $this;
    }

    public function take(int $limit): static
    {
        return $this;
    }

    public function pageSize(int $size): static
    {
        return $this;
    }

    public function get(): Collection
    {
        return collect();
    }

    public function count(): int
    {
        return 0;
    }

    public function first(): ?MessageDto
    {
        return null;
    }

    public function markAsRead(array $messageIds): void {}

    public function markAsUnread(array $messageIds): void {}

    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void {}

    public function resolvedFolder(): ?string
    {
        return $this->inFolderCalls[0] ?? null;
    }
}
