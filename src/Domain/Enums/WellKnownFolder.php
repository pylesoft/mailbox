<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum WellKnownFolder: string
{
    case INBOX = 'inbox';
    case DRAFTS = 'drafts';
    case SENT = 'sent';
    case DELETED = 'deleted';
    case JUNK = 'junk';
    case ARCHIVE = 'archive';
    case OUTBOX = 'outbox';

    public function forDriver(string $driver): string
    {
        $driver = strtolower($driver);

        return match ($driver) {
            'ms-graph' => match ($this) {
                self::INBOX => 'Inbox',
                self::DRAFTS => 'Drafts',
                self::SENT => 'SentItems',
                self::DELETED => 'DeletedItems',
                self::JUNK => 'JunkEmail',
                self::ARCHIVE => 'Archive',
                self::OUTBOX => 'Outbox',
            },
            'gmail' => match ($this) {
                self::INBOX => 'INBOX',
                self::DRAFTS => 'DRAFT',
                self::SENT => 'SENT',
                self::DELETED => 'TRASH',
                self::JUNK => 'SPAM',
                self::ARCHIVE => 'ALL_MAIL',
                self::OUTBOX => 'OUTBOX',
            },
            default => $this->value,
        };
    }
}
