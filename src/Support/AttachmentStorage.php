<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\Events\AttachmentDownloaded;
use Pyle\Mailbox\Events\AttachmentSkipped;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;

final class AttachmentStorage
{
    public static function store(StreamInterface $content, AttachmentDto $attachment, ResponseBudget $budget, string $driver, string $mailbox, string $messageId, string $attachmentId): AttachmentFileDto
    {
        $resource = $content->detach();
        if (! is_resource($resource)) {
            throw new MailboxException('The validated attachment stream is unavailable.');
        }

        try {
            $budget->assertMemoryAvailable(65536);
            rewind($resource);
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $resource);
            $contentHash = hash_final($hash);
            rewind($resource);
            $disk = (string) config('mailbox.attachment_disk', 'local');
            $basePath = (string) config('mailbox.attachment_path', 'mailbox-attachments');
            $name = $attachment->name !== '' ? $attachment->name : $attachment->id;
            $reservation = 65536;
            foreach ([$basePath, $mailbox, $messageId, $name] as $segment) {
                $bytes = strlen($segment);
                if ($bytes > intdiv(PHP_INT_MAX - $reservation, 16)) {
                    throw new ResponseLimitExceededException('The attachment path allocation estimate exceeded its limit.');
                }
                $reservation += $bytes * 16;
            }
            $budget->assertMemoryAvailable($reservation);
            $basePath = trim($basePath, '/');
            $safeMailbox = self::sanitizePathSegment(strtolower(str_replace(['@', '.'], ['_', '_'], $mailbox)), 'mailbox');
            $safeMessageId = self::sanitizePathSegment($messageId, 'message');
            $fileName = self::sanitizeFileName($name);
            $preferredPath = sprintf('%s/%s/%s/%s', $basePath, $safeMailbox, $safeMessageId, $fileName);
            $destination = self::resolveDestinationPath($disk, $preferredPath, $contentHash, $size, $budget);

            if ($destination['alreadyExisted']) {
                Event::dispatch(new AttachmentSkipped($driver, $mailbox, $messageId, $attachmentId, $destination['path']));
            } else {
                $budget->assertMemoryAvailable(65536);
                if (! Storage::disk($disk)->put($destination['path'], $resource)) {
                    throw new MailboxException('The attachment could not be stored completely.');
                }
                Event::dispatch(new AttachmentDownloaded($driver, $mailbox, $messageId, $attachmentId, $destination['path'], $disk));
            }

            return new AttachmentFileDto(
                id: $attachment->id,
                name: $attachment->name,
                contentType: $attachment->contentType,
                size: $size,
                isInline: $attachment->isInline,
                contentId: $attachment->contentId,
                path: $destination['path'],
                disk: $disk,
                alreadyExisted: $destination['alreadyExisted'],
            );
        } finally {
            fclose($resource);
        }
    }

    private static function sanitizeFileName(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return $sanitized !== null && trim($sanitized, '.') !== '' ? $sanitized : 'attachment.bin';
    }

    private static function sanitizePathSegment(string $name, string $fallback): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._+=-]/', '_', $name);

        return $sanitized !== null && trim($sanitized, '.') !== '' ? $sanitized : $fallback;
    }

    /** @return array{path: string, alreadyExisted: bool} */
    private static function resolveDestinationPath(string $disk, string $preferredPath, string $contentHash, int $size, ResponseBudget $budget): array
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($preferredPath)) {
            return ['path' => $preferredPath, 'alreadyExisted' => false];
        }
        $existingHash = self::hashForPath($disk, $preferredPath, $size, $budget);
        if ($existingHash !== null && hash_equals($existingHash, $contentHash)) {
            return ['path' => $preferredPath, 'alreadyExisted' => true];
        }
        $path = self::withHashSuffix($preferredPath, $contentHash);
        if ($storage->exists($path)) {
            $addressedHash = self::hashForPath($disk, $path, $size, $budget);
            if ($addressedHash !== null && hash_equals($addressedHash, $contentHash)) {
                return ['path' => $path, 'alreadyExisted' => true];
            }
            throw new MailboxException('The attachment destination already contains different content.');
        }

        return ['path' => $path, 'alreadyExisted' => false];
    }

    private static function hashForPath(string $disk, string $path, int $expectedSize, ResponseBudget $budget): ?string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($stream)) {
                $budget->assertMemoryAvailable(65536);
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    return null;
                }
                if (strlen($chunk) > $expectedSize - $size) {
                    return null;
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }

            return $size === $expectedSize ? hash_final($hash) : null;
        } finally {
            fclose($stream);
        }
    }

    private static function withHashSuffix(string $path, string $hash): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $name = $extension !== ''
            ? sprintf('%s-%s.%s', $filename, substr($hash, 0, 12), $extension)
            : sprintf('%s-%s', $filename, substr($hash, 0, 12));

        return trim($directory, '/').'/'.$name;
    }
}
