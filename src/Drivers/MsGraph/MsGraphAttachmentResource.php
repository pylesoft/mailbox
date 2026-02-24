<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\Events\AttachmentDownloaded;
use Pyle\Mailbox\Events\AttachmentSkipped;

class MsGraphAttachmentResource implements AttachmentResource
{
    public function __construct(
        private readonly GraphClient $client,
        private readonly string $mailbox,
        private readonly string $messageId,
        private readonly string $attachmentId,
    ) {}

    public function metadata(): AttachmentDto
    {
        $payload = $this->client->get($this->attachmentEndpoint(), mailbox: $this->mailbox);

        return AttachmentDto::fromMsGraph($payload);
    }

    public function download(): AttachmentFileDto
    {
        $payload = $this->client->get($this->attachmentEndpoint(), mailbox: $this->mailbox);
        $attachment = AttachmentDto::fromMsGraph($payload);

        $disk = (string) config('mailbox.attachment_disk', 'local');
        $basePath = trim((string) config('mailbox.attachment_path', 'mailbox-attachments'), '/');
        $safeMailbox = strtolower(str_replace(['@', '.'], ['_', '_'], $this->mailbox));
        $fileName = $this->sanitizeFileName($attachment->name !== '' ? $attachment->name : $attachment->id);
        $path = sprintf('%s/%s/%s/%s', $basePath, $safeMailbox, $this->messageId, $fileName);

        if (Storage::disk($disk)->exists($path)) {
            Event::dispatch(new AttachmentSkipped(
                driver: 'ms-graph',
                mailbox: $this->mailbox,
                messageId: $this->messageId,
                attachmentId: $this->attachmentId,
                path: $path,
            ));

            return new AttachmentFileDto(
                id: $attachment->id,
                name: $attachment->name,
                contentType: $attachment->contentType,
                size: $attachment->size,
                isInline: $attachment->isInline,
                contentId: $attachment->contentId,
                path: $path,
                disk: $disk,
                alreadyExisted: true,
            );
        }

        $content = isset($payload['contentBytes'])
            ? base64_decode((string) $payload['contentBytes'], true) ?: ''
            : (string) $this->stream();

        Storage::disk($disk)->put($path, $content);

        Event::dispatch(new AttachmentDownloaded(
            driver: 'ms-graph',
            mailbox: $this->mailbox,
            messageId: $this->messageId,
            attachmentId: $this->attachmentId,
            path: $path,
            disk: $disk,
        ));

        return new AttachmentFileDto(
            id: $attachment->id,
            name: $attachment->name,
            contentType: $attachment->contentType,
            size: $attachment->size,
            isInline: $attachment->isInline,
            contentId: $attachment->contentId,
            path: $path,
            disk: $disk,
            alreadyExisted: false,
        );
    }

    public function stream(): StreamInterface
    {
        $payload = $this->client->get($this->attachmentEndpoint(), mailbox: $this->mailbox);

        if (isset($payload['contentBytes'])) {
            return Utils::streamFor(base64_decode((string) $payload['contentBytes'], true) ?: '');
        }

        return $this->client->stream($this->attachmentEndpoint().'/$value', $this->mailbox);
    }

    private function attachmentEndpoint(): string
    {
        return sprintf(
            'users/%s/messages/%s/attachments/%s',
            rawurlencode($this->mailbox),
            rawurlencode($this->messageId),
            rawurlencode($this->attachmentId),
        );
    }

    private function sanitizeFileName(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return $sanitized !== null && $sanitized !== '' ? $sanitized : 'attachment.bin';
    }
}
