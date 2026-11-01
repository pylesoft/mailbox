<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\SupportsMessageMetadataRead;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Support\ResponseBudget;

class MsGraphMessageResource implements MessageResource, SupportsMessageMetadataRead
{
    private const METADATA_FIELDS = ['id', 'conversationId', 'parentFolderId', 'isDraft', 'receivedDateTime', 'sentDateTime'];

    private const ATTACHMENT_METADATA_SELECT = 'id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId';

    public function __construct(
        private readonly GraphClient $client,
        private readonly string $mailbox,
        private readonly string $messageId,
    ) {}

    public function get(): MessageDto
    {
        $payload = $this->client->getMessagePayload($this->messageEndpoint(), mailbox: $this->mailbox);

        return MessageDto::fromMsGraph($payload);
    }

    public function metadata(): MessageDto
    {
        $payload = $this->client->getBounded($this->messageEndpoint(), new ResponseBudget(8_388_608, 67_108_864),
            ['$select' => implode(',', self::METADATA_FIELDS)], $this->mailbox);
        if (($payload['id'] ?? null) !== $this->messageId || ! is_bool($payload['isDraft'] ?? null)
            || ! is_string($payload['conversationId'] ?? null) || trim($payload['conversationId']) === ''
            || ! is_string($payload['parentFolderId'] ?? null) || trim($payload['parentFolderId']) === '') {
            throw new MailboxException('Graph returned missing or malformed message selection metadata.');
        }
        foreach (['receivedDateTime', 'sentDateTime'] as $field) {
            if (isset($payload[$field]) && (! is_string($payload[$field]) || trim($payload[$field]) === '')) {
                throw new MailboxException('Graph returned malformed message activity metadata.');
            }
        }
        try {
            $message = MessageDto::fromMsGraph(array_intersect_key($payload, array_flip(self::METADATA_FIELDS)));
        } catch (InvalidFormatException) {
            throw new MailboxException('Graph returned malformed message activity metadata.');
        }
        if ($message->receivedAt === null && $message->sentAt === null) {
            throw new MailboxException('Graph returned message selection metadata without activity.');
        }

        return $message;
    }

    public function body(): BodyDto
    {
        $message = $this->get();

        return $message->body ?? new BodyDto('text', '');
    }

    public function markAsRead(): void
    {
        $this->client->patch($this->messageEndpoint(), ['isRead' => true], $this->mailbox);
    }

    public function markAsUnread(): void
    {
        $this->client->patch($this->messageEndpoint(), ['isRead' => false], $this->mailbox);
    }

    public function moveTo(string|WellKnownFolder $folder): MessageDto
    {
        $payload = $this->client->post($this->messageEndpoint().'/move', [
            'destinationId' => FolderIdResolver::resolve($folder),
        ], $this->mailbox);

        return MessageDto::fromMsGraph($payload);
    }

    public function copyTo(string|WellKnownFolder $folder): MessageDto
    {
        $payload = $this->client->post($this->messageEndpoint().'/copy', [
            'destinationId' => FolderIdResolver::resolve($folder),
        ], $this->mailbox);

        return MessageDto::fromMsGraph($payload);
    }

    public function delete(): void
    {
        $this->client->delete($this->messageEndpoint(), $this->mailbox);
    }

    /** @return Collection<int, AttachmentDto> */
    public function attachments(): Collection
    {
        $payload = $this->client->get(
            $this->messageEndpoint().'/attachments',
            ['$select' => self::ATTACHMENT_METADATA_SELECT],
            $this->mailbox,
        );

        return collect((array) ($payload['value'] ?? []))
            ->map(fn (mixed $attachment): AttachmentDto => AttachmentDto::fromMsGraph(is_array($attachment) ? $attachment : []))
            ->values();
    }

    public function attachment(string $attachmentId): AttachmentResource
    {
        return new MsGraphAttachmentResource($this->client, $this->mailbox, $this->messageId, $attachmentId);
    }

    /** @return Collection<int, AttachmentFileDto> */
    public function downloadAttachments(bool $includeInline = false): Collection
    {
        return $this->attachments()
            ->filter(fn (AttachmentDto $attachment): bool => $includeInline || ! $attachment->isInline)
            ->map(fn (AttachmentDto $attachment): AttachmentFileDto => $this->attachment($attachment->id)->download())
            ->values();
    }

    private function messageEndpoint(): string
    {
        return sprintf('users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($this->messageId));
    }
}
