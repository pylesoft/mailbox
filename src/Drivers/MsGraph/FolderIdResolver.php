<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Pyle\Mailbox\Enums\WellKnownFolder;

class FolderIdResolver
{
    public static function resolve(string|WellKnownFolder $folder): string
    {
        if (is_string($folder)) {
            return $folder;
        }

        return match ($folder) {
            WellKnownFolder::INBOX => 'inbox',
            WellKnownFolder::DRAFTS => 'drafts',
            WellKnownFolder::SENT => 'sentitems',
            WellKnownFolder::DELETED => 'deleteditems',
            WellKnownFolder::JUNK => 'junkemail',
            WellKnownFolder::ARCHIVE => 'archive',
            WellKnownFolder::OUTBOX => 'outbox',
        };
    }
}
