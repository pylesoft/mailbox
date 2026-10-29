<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\Exceptions\MailboxException;
use ZBateson\MailMimeParser\Header\ParameterHeader;

final class GmailMime
{
    /** @param array<string, mixed> $part */
    public static function isBody(array $part): bool
    {
        return in_array(strtolower((string) ($part['mimeType'] ?? '')), ['text/plain', 'text/html'], true)
            && ! self::isFile($part);
    }

    /** @param array<string, mixed> $part */
    public static function isFile(array $part): bool
    {
        if (self::filename($part) !== '') {
            return true;
        }
        foreach ((array) ($part['headers'] ?? []) as $header) {
            if (is_array($header) && strtolower((string) ($header['name'] ?? '')) === 'content-disposition'
                && preg_match('/^attachment(?:\s*;|\s*$)/i', trim((string) ($header['value'] ?? ''))) === 1) {
                return true;
            }
            if (is_array($header) && strtolower((string) ($header['name'] ?? '')) === 'content-id' && trim((string) ($header['value'] ?? '')) !== '') {
                return true;
            }
        }

        $mime = strtolower((string) ($part['mimeType'] ?? ''));

        return $mime !== '' && ! str_starts_with($mime, 'multipart/') && ! in_array($mime, ['text/plain', 'text/html'], true);
    }

    /** @param array<string, mixed> $part */
    public static function body(array $part): ?BodyDto
    {
        if (self::isFile($part)) {
            return null;
        }
        if (self::isBody($part)) {
            return BodyDto::fromGmail($part);
        }
        $bodies = [];
        foreach (self::children($part) as $child) {
            $body = self::body($child);
            if ($body !== null) {
                $bodies[] = $body;
            }
        }
        if ($bodies === []) {
            return null;
        }
        if (strtolower((string) ($part['mimeType'] ?? '')) === 'multipart/alternative') {
            $html = array_values(array_filter($bodies, static fn (BodyDto $body): bool => $body->contentType === 'html'));

            return $html !== [] ? $html[array_key_last($html)] : $bodies[array_key_last($bodies)];
        }
        $type = $bodies[0]->contentType;
        foreach ($bodies as $body) {
            if ($body->contentType !== $type) {
                throw new MailboxException('Mixed Gmail body formats cannot be represented completely by one body.');
            }
        }

        return new BodyDto($type, implode("\n", array_map(static fn (BodyDto $body): string => $body->content, $bodies)));
    }

    /** @param array<string, mixed> $part */
    public static function filename(array $part): string
    {
        $filename = trim((string) ($part['filename'] ?? $part['name'] ?? ''));
        foreach (self::headers($part) as $header) {
            if ($filename !== '' || ! in_array(strtolower($header['name']), ['content-disposition', 'content-type'], true)) {
                continue;
            }
            $parsed = new ParameterHeader($header['name'], $header['value']);
            if ($parsed->hasAnyErrors()) {
                throw new MailboxException('Malformed Gmail MIME parameters.');
            }
            $filename = $parsed->getValueFor('filename') ?? $parsed->getValueFor('name') ?? '';
        }

        return $filename;
    }

    /**
     * @param array<string, mixed> $part
     * @return list<array{name: string, value: string}>
     */
    public static function headers(array $part): array
    {
        $headers = $part['headers'] ?? [];
        if (! is_array($headers) || ! array_is_list($headers)) {
            throw new MailboxException('Malformed Gmail MIME headers.');
        }
        foreach ($headers as $header) {
            if (! is_array($header) || ! is_string($header['name'] ?? null) || ! is_string($header['value'] ?? null)) {
                throw new MailboxException('Malformed Gmail MIME header.');
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $part
     * @return list<array<string, mixed>>
     */
    public static function children(array $part): array
    {
        $children = $part['parts'] ?? [];
        if (! is_array($children) || ! array_is_list($children)) {
            throw new MailboxException('Malformed Gmail MIME parts.');
        }
        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new MailboxException('Missing Gmail MIME part.');
            }
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $part
     * @return list<array<string, mixed>>
     */
    public static function files(array $part): array
    {
        if (self::isFile($part)) {
            return [$part];
        }
        $files = [];
        foreach (self::children($part) as $child) {
            array_push($files, ...self::files($child));
        }

        return $files;
    }
}
