<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Collection;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

final class TestMessageResource implements MessageResource
{
    /**
     * @param  Collection<int, AttachmentDto>  $attachments
     * @param  array<string, string|Closure(): StreamInterface>  $streams
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
    /** @param  string|Closure(): StreamInterface  $streamContent */
    public function __construct(private readonly string|Closure $streamContent) {}

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
        return $this->streamContent instanceof Closure
            ? ($this->streamContent)()
            : Utils::streamFor($this->streamContent);
    }
}
