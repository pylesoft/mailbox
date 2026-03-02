<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Collection;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Enums\Importance;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\MailboxAttachment;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Services\Persistence\MessageMoveService;
use Pyle\Mailbox\Services\Persistence\MessageSyncService;

afterEach(function (): void {
    \Mockery::close();
});

it('syncs and upserts mailbox messages and attachments using canonical keys', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Sync Connection',
        'driver' => 'ms-graph',
        'status' => ConnectionStatus::CONNECTED,
        'config' => [],
    ]);

    $mailbox = Mailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => 'sync@example.com',
        'display_name' => 'Sync Mailbox',
        'is_active' => true,
    ]);

    $firstMessage = testMailboxMessageDto(
        id: 'provider-message-1',
        internetMessageId: '<internet-1@example.com>',
        parentFolderId: 'inbox',
    );

    $secondMessage = testMailboxMessageDto(
        id: 'provider-message-2',
        internetMessageId: '<internet-1@example.com>',
        parentFolderId: 'archive-folder',
    );

    $attachment = new AttachmentDto(
        id: 'attachment-1',
        name: 'invoice.pdf',
        contentType: 'application/pdf',
        size: 1024,
        isInline: false,
        contentId: null,
    );

    $resourceFirst = new TestMailboxResource(
        query: new TestMessageQueryBuilder(collect([$firstMessage])),
        messages: [
            'provider-message-1' => new TestMessageResource(
                dto: $firstMessage,
                attachments: collect([$attachment]),
                streams: ['attachment-1' => 'first-binary-content'],
            ),
        ],
    );

    $resourceSecond = new TestMailboxResource(
        query: new TestMessageQueryBuilder(collect([$secondMessage])),
        messages: [
            'provider-message-2' => new TestMessageResource(
                dto: $secondMessage,
                attachments: collect([$attachment]),
                streams: ['attachment-1' => 'second-binary-content'],
            ),
        ],
    );

    MailboxFacade::shouldReceive('forMailbox')
        ->twice()
        ->with(\Mockery::on(fn (Mailbox $model): bool => $model->is($mailbox)))
        ->andReturn($resourceFirst, $resourceSecond);

    $service = new MessageSyncService;

    $firstPersisted = $service->syncMailbox($mailbox, [
        'folder_reference' => 'wk:inbox',
        'filters' => ['limit' => 25],
    ]);

    expect($firstPersisted)->toHaveCount(1);
    expect(MailboxMessage::query()->count())->toBe(1);
    expect(MailboxAttachment::query()->count())->toBe(1);

    $secondPersisted = $service->syncMailbox($mailbox, [
        'folder_reference' => 'wk:inbox',
        'filters' => ['limit' => 25],
    ]);

    expect($secondPersisted)->toHaveCount(1);
    expect(MailboxMessage::query()->count())->toBe(1);
    expect(MailboxAttachment::query()->count())->toBe(1);

    $storedMessage = MailboxMessage::query()->first();
    expect($storedMessage)->not->toBeNull()
        ->and($storedMessage?->provider_message_id)->toBe('provider-message-2')
        ->and($storedMessage?->canonical_message_key)->toBe('internet:<internet-1@example.com>')
        ->and($storedMessage?->parent_folder_id)->toBe('archive-folder');

    $storedAttachment = MailboxAttachment::query()->first();
    expect($storedAttachment)->not->toBeNull()
        ->and($storedAttachment?->provider_attachment_id)->toBe('attachment-1')
        ->and($storedAttachment?->content_bytes)->toBe(base64_encode('second-binary-content'));

    expect($mailbox->fresh()?->last_synced_at)->not->toBeNull();
});

it('moves a mailbox message and updates provider id and folder metadata', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Move Connection',
        'driver' => 'ms-graph',
        'status' => ConnectionStatus::CONNECTED,
        'config' => [],
    ]);

    $mailbox = Mailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => 'move@example.com',
        'display_name' => 'Move Mailbox',
        'is_active' => true,
    ]);

    $message = MailboxMessage::query()->create([
        'mailbox_id' => $mailbox->id,
        'provider_message_id' => 'provider-before-move',
        'canonical_message_key' => 'provider:provider-before-move',
        'internet_message_id' => null,
        'subject' => 'Move test',
        'is_read' => false,
        'is_draft' => false,
        'has_attachments' => false,
        'importance' => 'normal',
    ]);

    $movedDto = testMailboxMessageDto(
        id: 'provider-after-move',
        internetMessageId: null,
        parentFolderId: 'archive-folder',
    );

    $resource = new TestMailboxResource(
        query: new TestMessageQueryBuilder(collect()),
        messages: [
            'provider-before-move' => new TestMessageResource(
                dto: $movedDto,
                attachments: collect(),
                streams: [],
            ),
        ],
    );

    MailboxFacade::shouldReceive('forMailbox')
        ->once()
        ->with(\Mockery::on(fn (Mailbox $model): bool => $model->is($mailbox)))
        ->andReturn($resource);

    $service = new MessageMoveService;
    $moved = $service->move($message, WellKnownFolder::ARCHIVE);

    expect($moved->provider_message_id)->toBe('provider-after-move')
        ->and($moved->canonical_message_key)->toBe('provider:provider-after-move')
        ->and($moved->parent_folder_id)->toBe('archive-folder');
});

function testMailboxMessageDto(string $id, ?string $internetMessageId, ?string $parentFolderId): MessageDto
{
    return new MessageDto(
        id: $id,
        subject: 'Invoice',
        bodyPreview: 'Preview',
        body: new BodyDto(contentType: 'text', content: 'Body'),
        from: new EmailAddressDto(name: 'Sender', address: 'sender@example.com'),
        sender: new EmailAddressDto(name: 'Sender', address: 'sender@example.com'),
        toRecipients: [new EmailAddressDto(name: 'Receiver', address: 'receiver@example.com')],
        ccRecipients: [],
        bccRecipients: [],
        receivedAt: CarbonImmutable::parse('2026-03-01 11:00:00', 'UTC'),
        sentAt: CarbonImmutable::parse('2026-03-01 10:59:00', 'UTC'),
        isRead: false,
        isDraft: false,
        hasAttachments: true,
        importance: Importance::NORMAL,
        conversationId: 'conversation-1',
        internetMessageId: $internetMessageId,
        parentFolderId: $parentFolderId,
        raw: ['id' => $id],
    );
}

final class TestMailboxResource implements MailboxResource
{
    /**
     * @param  array<string, MessageResource>  $messages
     */
    public function __construct(
        private readonly MessageQueryBuilder $query,
        private readonly array $messages,
    ) {}

    public function messages(): MessageQueryBuilder
    {
        return $this->query;
    }

    public function message(string $messageId): MessageResource
    {
        $resource = $this->messages[$messageId] ?? null;

        if (! $resource instanceof MessageResource) {
            throw new RuntimeException('Unknown message id: '.$messageId);
        }

        return $resource;
    }

    public function folders(): FolderQueryBuilder
    {
        throw new RuntimeException('Not used in this test.');
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        throw new RuntimeException('Not used in this test.');
    }
}

final class TestMessageQueryBuilder implements MessageQueryBuilder
{
    /**
     * @param  Collection<int, MessageDto>  $messages
     */
    public function __construct(private readonly Collection $messages) {}

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

    public function get(): Collection
    {
        return $this->messages;
    }

    public function count(): int
    {
        return $this->messages->count();
    }

    public function first(): ?MessageDto
    {
        return $this->messages->first();
    }

    public function markAsRead(array $messageIds): void {}

    public function markAsUnread(array $messageIds): void {}

    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void {}
}

final class TestMessageResource implements MessageResource
{
    /**
     * @param  Collection<int, AttachmentDto>  $attachments
     * @param  array<string, string>  $streams
     */
    public function __construct(
        private readonly MessageDto $dto,
        private readonly Collection $attachments,
        private readonly array $streams,
    ) {}

    public function get(): MessageDto
    {
        return $this->dto;
    }

    public function body(): BodyDto
    {
        return $this->dto->body ?? new BodyDto(contentType: 'text', content: '');
    }

    public function markAsRead(): void {}

    public function markAsUnread(): void {}

    public function moveTo(string|WellKnownFolder $folder): MessageDto
    {
        return $this->dto;
    }

    public function copyTo(string|WellKnownFolder $folder): MessageDto
    {
        return $this->dto;
    }

    public function delete(): void {}

    public function attachments(): Collection
    {
        return $this->attachments;
    }

    public function attachment(string $attachmentId): AttachmentResource
    {
        $stream = $this->streams[$attachmentId] ?? '';

        return new TestAttachmentResource($stream);
    }

    public function downloadAttachments(bool $includeInline = false): Collection
    {
        return collect();
    }
}

final class TestAttachmentResource implements AttachmentResource
{
    public function __construct(private readonly string $streamContent) {}

    public function metadata(): AttachmentDto
    {
        throw new RuntimeException('Not used in this test.');
    }

    public function download(): AttachmentFileDto
    {
        throw new RuntimeException('Not used in this test.');
    }

    public function stream(): StreamInterface
    {
        return Utils::streamFor($this->streamContent);
    }
}
