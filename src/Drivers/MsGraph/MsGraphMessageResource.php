<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

class MsGraphMessageResource implements MessageResource
{
    public function __construct(
        private readonly GraphClient $client,
        private readonly string $mailbox,
        private readonly string $messageId,
    ) {}

    public function get(): MessageDto
    {
        $payload = $this->client->get($this->messageEndpoint(), mailbox: $this->mailbox);

        return MessageDto::fromMsGraph($payload);
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
        $payload = $this->client->get($this->messageEndpoint().'/attachments', mailbox: $this->mailbox);

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
