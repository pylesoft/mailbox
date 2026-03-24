<?php

declare(strict_types=1);

use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Enums\WellKnownFolder;

final class TestMailboxResource implements MailboxResource
{
    /**
     * @param  array<string, MessageResource>  $messages
     */
    public function __construct(
        private readonly MessageQueryBuilder $query,
        private readonly array $messages,
    ) {}

    public function messages(): MessageQueryBuilder
    {
        return $this->query;
    }

    public function message(string $messageId): MessageResource
    {
        $resource = $this->messages[$messageId] ?? null;

        if (! $resource instanceof MessageResource) {
            throw new RuntimeException('Unknown message id: '.$messageId);
        }

        return $resource;
    }

    public function folders(): FolderQueryBuilder
    {
        throw new RuntimeException('Not used in this test.');
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        throw new RuntimeException('Not used in this test.');
    }
}

final class TrackingMailboxResource implements MailboxResource
{
    public int $messageCalls = 0;

    /**
     * @param  array<string, MessageResource>  $messages
     */
    public function __construct(
        private readonly MessageQueryBuilder $query,
        private readonly array $messages,
    ) {}

    public function messages(): MessageQueryBuilder
    {
        return $this->query;
    }

    public function message(string $messageId): MessageResource
    {
        $this->messageCalls++;

        $resource = $this->messages[$messageId] ?? null;

        if (! $resource instanceof MessageResource) {
            throw new RuntimeException('Unknown message id: '.$messageId);
        }

        return $resource;
    }

    public function folders(): FolderQueryBuilder
    {
        throw new RuntimeException('Not used in this test.');
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        throw new RuntimeException('Not used in this test.');
    }
}
