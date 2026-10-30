<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GraphUploadSession
{
    public function __construct(
        public GrantContext $context,
        public string $draftId,
        public string $fileIdentity,
        public int $byteLength,
        #[\SensitiveParameter] private string $fingerprint,
        #[\SensitiveParameter] private string $uploadUrl,
        public DateTimeImmutable $expiresAt,
    ) {
        $this->uploadUrl();
    }

    public function matches(GrantContext $context, string $draftId, PreparedFile $file): bool
    {
        return $this->context->matches($context) && $this->draftId === $draftId
            && $this->fileIdentity === $file->identity && $this->byteLength === $file->byteLength
            && hash_equals($this->fingerprint, $file->fingerprint());
    }

    public function uploadUrl(): string
    {
        $url = parse_url($this->uploadUrl);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'outlook.office.com'
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || isset($url['port'])
            || ! is_string($url['query'] ?? null) || $url['query'] === ''
            || preg_match('/[\x00-\x20\x7f\\\\]/', $this->uploadUrl) === 1
            || preg_match("~\\A/api/(?:g?v1|v2)\\.0/Users\\('[^']+'\\)/Messages\\('[^']+'\\)/AttachmentSessions\\('[^']+'\\)\\z~i", $url['path'] ?? '') !== 1) {
            throw new InvalidArgumentException('The Outlook upload session has an invalid trusted target.');
        }

        return $this->uploadUrl;
    }

    public function attachmentId(#[\SensitiveParameter] ?string $location): ?string
    {
        if ($location === null || strlen($location) > 16_384 || preg_match('/[\x00-\x20\x7f\\\\]/', $location) === 1) {
            return null;
        }
        $url = parse_url($location);
        $path = parse_url($this->uploadUrl(), PHP_URL_PATH);
        if (! is_string($path) || ($separator = strrpos($path, '/')) === false) {
            return null;
        }
        $parent = substr($path, 0, $separator);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'outlook.office.com'
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || isset($url['port']) || isset($url['query'])
            || ! str_starts_with($url['path'] ?? '', $parent.'/Attachments(')
            || preg_match('~\\A'.preg_quote($parent, '~')."/Attachments\\('([^']+)'\\)\\z~", $url['path'], $match) !== 1) {
            return null;
        }
        $id = rawurldecode($match[1]);

        return preg_match('/\A[A-Za-z0-9_+=\/-]{1,2048}\z/', $id) === 1 ? $id : null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['context' => $this->context, 'draftId' => $this->draftId,
            'fileIdentity' => $this->fileIdentity, 'byteLength' => $this->byteLength, 'expiresAt' => $this->expiresAt];
    }
}
