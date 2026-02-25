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
        $content = $this->resolveContent($payload);
        $contentHash = hash('sha256', $content);

        $disk = (string) config('mailbox.attachment_disk', 'local');
        $basePath = trim((string) config('mailbox.attachment_path', 'mailbox-attachments'), '/');
        $safeMailbox = strtolower(str_replace(['@', '.'], ['_', '_'], $this->mailbox));
        $fileName = $this->sanitizeFileName($attachment->name !== '' ? $attachment->name : $attachment->id);
        $preferredPath = sprintf('%s/%s/%s/%s', $basePath, $safeMailbox, $this->messageId, $fileName);
        $resolvedPath = $this->resolveDestinationPath($disk, $preferredPath, $contentHash);

        if ($resolvedPath['alreadyExisted']) {
            Event::dispatch(new AttachmentSkipped(
                driver: 'ms-graph',
                mailbox: $this->mailbox,
                messageId: $this->messageId,
                attachmentId: $this->attachmentId,
                path: $resolvedPath['path'],
            ));

            return new AttachmentFileDto(
                id: $attachment->id,
                name: $attachment->name,
                contentType: $attachment->contentType,
                size: $attachment->size,
                isInline: $attachment->isInline,
                contentId: $attachment->contentId,
                path: $resolvedPath['path'],
                disk: $disk,
                alreadyExisted: true,
            );
        }

        Storage::disk($disk)->put($resolvedPath['path'], $content);

        Event::dispatch(new AttachmentDownloaded(
            driver: 'ms-graph',
            mailbox: $this->mailbox,
            messageId: $this->messageId,
            attachmentId: $this->attachmentId,
            path: $resolvedPath['path'],
            disk: $disk,
        ));

        return new AttachmentFileDto(
            id: $attachment->id,
            name: $attachment->name,
            contentType: $attachment->contentType,
            size: $attachment->size,
            isInline: $attachment->isInline,
            contentId: $attachment->contentId,
            path: $resolvedPath['path'],
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

    /** @param array<string, mixed> $payload */
    private function resolveContent(array $payload): string
    {
        if (isset($payload['contentBytes'])) {
            return base64_decode((string) $payload['contentBytes'], true) ?: '';
        }

        return (string) $this->stream();
    }

    /**
     * @return array{path:string,alreadyExisted:bool}
     */
    private function resolveDestinationPath(string $disk, string $preferredPath, string $contentHash): array
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($preferredPath)) {
            return ['path' => $preferredPath, 'alreadyExisted' => false];
        }

        $existingHash = $this->hashForPath($disk, $preferredPath);
        if ($existingHash !== null && hash_equals($existingHash, $contentHash)) {
            return ['path' => $preferredPath, 'alreadyExisted' => true];
        }

        $contentAddressedPath = $this->withHashSuffix($preferredPath, $contentHash);
        if ($storage->exists($contentAddressedPath)) {
            $addressedHash = $this->hashForPath($disk, $contentAddressedPath);
            if ($addressedHash !== null && hash_equals($addressedHash, $contentHash)) {
                return ['path' => $contentAddressedPath, 'alreadyExisted' => true];
            }
        }

        return ['path' => $contentAddressedPath, 'alreadyExisted' => false];
    }

    private function hashForPath(string $disk, string $path): ?string
    {
        $contents = Storage::disk($disk)->get($path);

        return is_string($contents) ? hash('sha256', $contents) : null;
    }

    private function withHashSuffix(string $path, string $hash): string
    {
        $suffix = substr($hash, 0, 12);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $filename = pathinfo($path, PATHINFO_FILENAME);

        $nameWithHash = $extension !== ''
            ? sprintf('%s-%s.%s', $filename, $suffix, $extension)
            : sprintf('%s-%s', $filename, $suffix);

        return trim($directory, '/').'/'.$nameWithHash;
    }
}
