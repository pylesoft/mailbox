<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Enums\Importance;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\MailboxManager;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\Folder;
use Pyle\Mailbox\Models\Mailbox;

beforeEach(function (): void {
    config()->set('mailbox.default', 'fake');
    config()->set('mailbox.drivers.fake', ['driver' => 'fake']);

    /** @var MailboxManager $manager */
    $manager = app(MailboxManager::class);
    $manager->extend('fake', fn (): MailboxDriver => new FakeMailboxDriver);
});

it('runs mailbox:test-access successfully', function (): void {
    $this->artisan('mailbox:test-access invoices@example.com --driver=fake')
        ->assertSuccessful();
});

it('runs mailbox:health successfully', function (): void {
    $this->artisan('mailbox:health --driver=fake')
        ->assertSuccessful();
});

it('runs mailbox:folders and mailbox:find-folder successfully', function (): void {
    $this->artisan('mailbox:folders invoices@example.com --driver=fake --tree --max-depth=3')
        ->assertSuccessful();

    $this->artisan('mailbox:find-folder invoices@example.com Processed --driver=fake')
        ->assertSuccessful();
});

it('runs mailbox:sync and mailbox:status successfully', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Primary',
        'driver' => 'fake',
        'status' => ConnectionStatus::CONNECTED,
    ]);

    $mailbox = Mailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => 'invoices@example.com',
        'display_name' => 'Invoices',
        'is_active' => true,
    ]);

    $folder = Folder::query()->create([
        'mailbox_id' => $mailbox->id,
        'folder_id' => 'inbox',
        'display_name' => 'Inbox',
        'is_active' => true,
        'sync_status' => SyncStatus::IDLE,
    ]);

    $this->artisan('mailbox:sync --folder='.$folder->id)
        ->assertSuccessful();

    $folder = $folder->fresh();
    expect($folder?->delta_token)->toBe('next-delta-token');

    $this->artisan('mailbox:status')
        ->assertSuccessful();
});

class FakeMailboxDriver implements MailboxDriver
{
    public function mailbox(string $emailAddress): MailboxResource
    {
        return new FakeMailboxResource($emailAddress);
    }

    public function testConnection(?string $emailAddress = null): ConnectionTestResult
    {
        return new ConnectionTestResult(
            success: true,
            error: null,
            latencyMs: 25,
            authenticatedAs: 'fake-client',
            accessibleMailboxes: $emailAddress !== null ? [$emailAddress] : [],
        );
    }

    public function healthCheck(): HealthCheckResult
    {
        return new HealthCheckResult(
            healthy: true,
            tokenValid: true,
            tokenExpiresIn: 3600,
            apiReachable: true,
            latencyMs: 10,
            secretExpiresAt: null,
            secretExpirationWarning: false,
        );
    }
}

class FakeMailboxResource implements MailboxResource
{
    public function __construct(private readonly string $emailAddress) {}

    public function messages(): MessageQueryBuilder
    {
        return new FakeMessageQueryBuilder;
    }

    public function message(string $messageId): MessageResource
    {
        return new FakeMessageResource($messageId);
    }

    public function folders(): FolderQueryBuilder
    {
        return new FakeFolderQueryBuilder;
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        $resolved = is_string($folderId) ? $folderId : $folderId->value;

        return new FakeFolderResource($this->emailAddress, $resolved);
    }
}

class FakeMessageQueryBuilder implements MessageQueryBuilder
{
    public function inFolder(string|WellKnownFolder $folder): static
    {
        return $this;
    }

    public function allFolders(): static
    {
        return $this;
    }

    public function where(\Pyle\Mailbox\Enums\FilterableField|string $field, mixed $operator, mixed $value = null): static
    {
        return $this;
    }

    public function whereAny(\Pyle\Mailbox\Enums\FilterableField|string $field, mixed $operator, array $values): static
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

    public function count(): int
    {
        return 1;
    }

    public function markAsRead(array $messageIds): void {}

    public function markAsUnread(array $messageIds): void {}

    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void {}

    public function first(): ?MessageDto
    {
        return $this->get()->first();
    }

    /** @return Collection<int, MessageDto> */
    public function get(): Collection
    {
        return collect([
            new MessageDto(
                id: 'm-1',
                subject: 'Test',
                bodyPreview: 'Preview',
                body: null,
                from: null,
                sender: null,
                toRecipients: [],
                ccRecipients: [],
                bccRecipients: [],
                receivedAt: now()->toImmutable(),
                sentAt: now()->toImmutable(),
                isRead: false,
                isDraft: false,
                hasAttachments: false,
                importance: Importance::NORMAL,
                conversationId: null,
                internetMessageId: '<id@test>',
                parentFolderId: 'inbox',
                raw: [],
            ),
        ]);
    }
}

class FakeMessageResource implements MessageResource
{
    public function __construct(private readonly string $messageId) {}

    public function get(): MessageDto
    {
        return new MessageDto(
            id: $this->messageId,
            subject: 'Test',
            bodyPreview: 'Preview',
            body: new BodyDto('text', 'Body'),
            from: null,
            sender: null,
            toRecipients: [],
            ccRecipients: [],
            bccRecipients: [],
            receivedAt: now()->toImmutable(),
            sentAt: now()->toImmutable(),
            isRead: false,
            isDraft: false,
            hasAttachments: false,
            importance: Importance::NORMAL,
            conversationId: null,
            internetMessageId: '<id@test>',
            parentFolderId: 'inbox',
            raw: [],
        );
    }

    public function body(): BodyDto
    {
        return new BodyDto('text', 'Body');
    }

    public function markAsRead(): void {}

    public function markAsUnread(): void {}

    public function moveTo(string|WellKnownFolder $folder): MessageDto
    {
        return $this->get();
    }

    public function copyTo(string|WellKnownFolder $folder): MessageDto
    {
        return $this->get();
    }

    public function delete(): void {}

    /** @return Collection<int, AttachmentDto> */
    public function attachments(): Collection
    {
        return collect();
    }

    public function attachment(string $attachmentId): AttachmentResource
    {
        return new class implements AttachmentResource
        {
            public function metadata(): AttachmentDto
            {
                return new AttachmentDto('a', 'a.txt', 'text/plain', 1, false, null);
            }

            public function download(): AttachmentFileDto
            {
                return new AttachmentFileDto('a', 'a.txt', 'text/plain', 1, false, null, 'x', 'local', true);
            }

            public function stream(): \Psr\Http\Message\StreamInterface
            {
                return \GuzzleHttp\Psr7\Utils::streamFor('');
            }
        };
    }

    /** @return Collection<int, AttachmentFileDto> */
    public function downloadAttachments(bool $includeInline = false): Collection
    {
        return collect();
    }
}

class FakeFolderQueryBuilder implements FolderQueryBuilder
{
    /** @return Collection<int, FolderDto> */
    public function get(): Collection
    {
        return $this->tree();
    }

    /** @return Collection<int, FolderDto> */
    public function tree(int $maxDepth = 10): Collection
    {
        return collect([
            new FolderDto('inbox', 'Inbox', null, 1, 10, 2, 'Inbox', WellKnownFolder::INBOX, [
                new FolderDto('processed', 'Processed', 'inbox', 0, 2, 0, 'Inbox/Processed', null, []),
            ]),
        ]);
    }

    public function find(string $name, string|WellKnownFolder|null $root = null, bool $caseSensitive = true): ?FolderDto
    {
        return $this->tree()->flatMap(fn (FolderDto $folder): array => [$folder, ...$folder->children])
            ->first(fn (FolderDto $folder): bool => strtolower($folder->displayName) === strtolower($name));
    }

    public function create(string $name, ?string $parentId = null): FolderDto
    {
        return new FolderDto('created', $name, $parentId, 0, 0, 0, $name, null, []);
    }

    public function createPath(string $path): FolderDto
    {
        return new FolderDto('created-path', basename($path), null, 0, 0, 0, $path, null, []);
    }
}

class FakeFolderResource implements FolderResource
{
    public function __construct(private readonly string $email, private readonly string $folderId) {}

    public function get(): FolderDto
    {
        return new FolderDto($this->folderId, 'Inbox', null, 0, 10, 1, 'Inbox', WellKnownFolder::INBOX, []);
    }

    /** @return Collection<int, FolderDto> */
    public function children(): Collection
    {
        return collect();
    }

    public function messages(): MessageQueryBuilder
    {
        return new FakeMessageQueryBuilder;
    }

    public function delta(?string $deltaToken = null): DeltaResultDto
    {
        return new DeltaResultDto(
            created: collect(),
            updated: collect(),
            deleted: collect(),
            deltaLink: 'next-delta-token',
            fullSyncRequired: false,
        );
    }

    public function delete(): void {}

    public function moveTo(string $destinationParentId): FolderDto
    {
        return new FolderDto($this->folderId, 'Inbox', $destinationParentId, 0, 0, 0, 'Inbox', WellKnownFolder::INBOX, []);
    }
}
