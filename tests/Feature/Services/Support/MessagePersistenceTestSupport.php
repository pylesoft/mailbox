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
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;

function createTestMailbox(
    string $connectionName,
    string $emailAddress,
    string $displayName,
    string $driver = 'ms-graph',
): Mailbox {
    $connection = MailboxConnection::query()->create([
        'name' => $connectionName,
        'driver' => $driver,
        'status' => ConnectionStatus::CONNECTED,
        'config' => [],
    ]);

    return Mailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => $emailAddress,
        'display_name' => $displayName,
        'is_active' => true,
    ]);
}

function expectMailboxFacadeForMailbox(Mailbox $mailbox, MailboxResource ...$resources): void
{
    MailboxFacade::shouldReceive('forMailbox')
        ->times(count($resources))
        ->with(\Mockery::on(fn (Mailbox $model): bool => $model->is($mailbox)))
        ->andReturn(...$resources);
}

function testMailboxMessageDto(
    string $id,
    ?string $internetMessageId,
    ?string $parentFolderId,
    bool $hasAttachments = true,
): MessageDto {
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
        hasAttachments: $hasAttachments,
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

final class TrackingMailboxResource implements MailboxResource
{
    public int $messageCalls = 0;

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
        $this->messageCalls++;

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

final class RecordingMessageQueryBuilder implements MessageQueryBuilder
{
    /** @var array<int, array{field:string, operator:mixed, value:mixed}> */
    public array $whereCalls = [];

    /** @var array<int, array{field:string, operator:mixed, values:array<int, mixed>}> */
    public array $whereAnyCalls = [];

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
        $this->whereCalls[] = [
            'field' => $field instanceof \Pyle\Mailbox\Enums\FilterableField ? $field->value : (string) $field,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    public function whereAny(\Pyle\Mailbox\Enums\FilterableField|string $field, mixed $operator, array $values): static
    {
        $this->whereAnyCalls[] = [
            'field' => $field instanceof \Pyle\Mailbox\Enums\FilterableField ? $field->value : (string) $field,
            'operator' => $operator,
            'values' => $values,
        ];

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
