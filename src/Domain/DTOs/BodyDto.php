<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;
use Pyle\Mailbox\Exceptions\MailboxException;
use ZBateson\MailMimeParser\Header\ParameterHeader;
use ZBateson\MailMimeParser\Header\Part\NameValuePart;
use ZBateson\MbWrapper\MbWrapper;

/** @implements Arrayable<string, mixed> */
final readonly class BodyDto implements Arrayable, JsonSerializable
{
    use ArraySerializable;

    public function __construct(
        public string $contentType,
        public string $content,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromMsGraph(array $data): self
    {
        return new self(
            contentType: strtolower((string) ($data['contentType'] ?? 'text')),
            content: (string) ($data['content'] ?? ''),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromGmail(array $data): self
    {
        $mimeType = strtolower((string) ($data['mimeType'] ?? 'text/plain'));
        $normalizedContentType = str_contains($mimeType, 'html') ? 'html' : 'text';
        $body = is_array($data['body'] ?? null) ? $data['body'] : [];
        if (! array_key_exists('data', $body) && (($body['size'] ?? null) !== 0 || isset($body['attachmentId']))) {
            throw new MailboxException('Gmail body content is missing or requires hydration.');
        }
        if (array_key_exists('data', $body) && ! is_string($body['data'])) {
            throw new MailboxException('Malformed Gmail body data.');
        }
        $content = self::decodeBase64Url($body['data'] ?? '');
        if (array_key_exists('size', $body) && (! is_int($body['size']) || $body['size'] < 0)) {
            throw new MailboxException('Gmail body size does not match its content.');
        }
        $charset = 'UTF-8';
        $charsets = [];
        foreach ((array) ($data['headers'] ?? []) as $header) {
            if (is_array($header) && strtolower((string) ($header['name'] ?? '')) === 'content-type') {
                $parsed = new ParameterHeader('Content-Type', (string) ($header['value'] ?? ''));
                if ($parsed->hasAnyErrors()) {
                    throw new MailboxException('Malformed Gmail body content type.');
                }
                $charset = $parsed->getValueFor('charset') ?? 'UTF-8';
                $charsets[strtolower($charset)] = true;
                foreach ($parsed->getParts() as $parameter) {
                    if ($parameter instanceof NameValuePart && strtolower($parameter->getName()) === 'charset') {
                        $charsets[strtolower($parameter->getValue())] = true;
                    }
                }
            }
        }
        if (array_key_exists('size', $body) && $body['size'] !== strlen($content)) {
            // Gmail may return UTF-8 data while retaining the original charset's byte count.
            try {
                $converter = new MbWrapper;
                $original = count($charsets) === 1 && $converter->checkEncoding($content, 'UTF-8')
                    ? $converter->convert($content, 'UTF-8', $charset) : null;
                $matches = $original !== null && strlen($original) === $body['size']
                    && $converter->convert($original, $charset, 'UTF-8') === $content;
            } catch (\Throwable) {
                $matches = false;
            }
            if (! $matches) {
                throw new MailboxException('Gmail body size does not match its content.');
            }

            return new self(contentType: $normalizedContentType, content: $content);
        }
        try {
            $converter = new MbWrapper;
            if (! $converter->checkEncoding($content, $charset)) {
                throw new MailboxException('Invalid Gmail body text encoding.');
            }
            $content = $converter->convert($content, $charset, 'UTF-8');
        } catch (\Throwable) {
            throw new MailboxException('Invalid or unsupported Gmail body charset.');
        }

        return new self(
            contentType: $normalizedContentType,
            content: $content,
        );
    }

    private static function decodeBase64Url(string $encoded): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $encoded) !== 1 || (str_contains($encoded, '=') && strlen($encoded) % 4 !== 0)) {
            throw new MailboxException('Malformed Gmail body base64url.');
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== rtrim($encoded, '=')) {
            throw new MailboxException('Malformed or truncated Gmail body base64url.');
        }

        return $decoded;
    }
}
