<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\Enums\WellKnownFolder;

interface MailboxResource
{
    public function messages(): MessageQueryBuilder;

    public function message(string $messageId): MessageResource;

    public function folders(): FolderQueryBuilder;

    public function folder(string|WellKnownFolder $folderId): FolderResource;
}
