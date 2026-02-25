<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\Events\AttachmentDownloaded;
use Pyle\Mailbox\Events\AttachmentSkipped;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;

class GmailAttachmentResource implements AttachmentResource
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly string $mailbox,
        private readonly string $messageId,
        private readonly string $attachmentId,
    ) {}

    public function metadata(): AttachmentDto
    {
        $part = $this->resolveAttachmentPart();

        return AttachmentDto::fromGmail($part);
    }

    public function download(): AttachmentFileDto
    {
        $part = $this->resolveAttachmentPart();
        $attachment = AttachmentDto::fromGmail($part);
        $content = $this->resolveContent($part);
        $contentHash = hash('sha256', $content);

        $disk = (string) config('mailbox.attachment_disk', 'local');
        $basePath = trim((string) config('mailbox.attachment_path', 'mailbox-attachments'), '/');
        $safeMailbox = strtolower(str_replace(['@', '.'], ['_', '_'], $this->mailbox));
        $fileName = $this->sanitizeFileName($attachment->name !== '' ? $attachment->name : $attachment->id);
        $preferredPath = sprintf('%s/%s/%s/%s', $basePath, $safeMailbox, $this->messageId, $fileName);
        $resolvedPath = $this->resolveDestinationPath($disk, $preferredPath, $contentHash);

        if ($resolvedPath['alreadyExisted']) {
            Event::dispatch(new AttachmentSkipped(
                driver: 'gmail',
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
            driver: 'gmail',
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
        return Utils::streamFor($this->resolveContent($this->resolveAttachmentPart()));
    }

    /** @return array<string, mixed> */
    private function resolveAttachmentPart(): array
    {
        $message = $this->client->get(
            sprintf('users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($this->messageId)),
            ['format' => 'full'],
            $this->mailbox,
        );

        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        $part = $this->findPart($payload);

        if (! is_array($part)) {
            throw new ResourceNotFoundException(
                resourceType: 'attachment',
                resourceId: $this->attachmentId,
                message: sprintf('Attachment %s was not found on message %s.', $this->attachmentId, $this->messageId),
            );
        }

        return $part;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function findPart(array $payload): ?array
    {
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

        if ((string) ($body['attachmentId'] ?? '') === $this->attachmentId || (string) ($payload['partId'] ?? '') === $this->attachmentId) {
            return $payload;
        }

        foreach ((array) ($payload['parts'] ?? []) as $part) {
            if (! is_array($part)) {
                continue;
            }

            $found = $this->findPart($part);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $part */
    private function resolveContent(array $part): string
    {
        $body = is_array($part['body'] ?? null) ? $part['body'] : [];
        $inlineData = (string) ($body['data'] ?? '');

        if ($inlineData !== '') {
            return $this->decodeBase64Url($inlineData);
        }

        $attachmentId = (string) ($body['attachmentId'] ?? '');

        if ($attachmentId === '') {
            return '';
        }

        $payload = $this->client->get(
            sprintf(
                'users/%s/messages/%s/attachments/%s',
                rawurlencode($this->mailbox),
                rawurlencode($this->messageId),
                rawurlencode($attachmentId),
            ),
            mailbox: $this->mailbox,
        );

        return $this->decodeBase64Url((string) ($payload['data'] ?? ''));
    }

    private function decodeBase64Url(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $normalized = strtr($encoded, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($normalized, true) ?: '';
    }

    private function sanitizeFileName(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return $sanitized !== null && $sanitized !== '' ? $sanitized : 'attachment.bin';
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
