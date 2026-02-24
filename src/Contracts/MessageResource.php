<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

interface MessageResource
{
    public function get(): MessageDto;

    public function body(): BodyDto;

    public function markAsRead(): void;

    public function markAsUnread(): void;

    public function moveTo(string|WellKnownFolder $folder): MessageDto;

    public function copyTo(string|WellKnownFolder $folder): MessageDto;

    public function delete(): void;

    /** @return Collection<int, AttachmentDto> */
    public function attachments(): Collection;

    public function attachment(string $attachmentId): AttachmentResource;

    /** @return Collection<int, AttachmentFileDto> */
    public function downloadAttachments(bool $includeInline = false): Collection;
}
