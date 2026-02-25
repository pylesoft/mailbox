<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Pyle\Mailbox\Enums\WellKnownFolder;

final class GmailLabelResolver
{
    public static function resolve(string|WellKnownFolder $folder): string
    {
        if ($folder instanceof WellKnownFolder) {
            return $folder->forDriver('gmail');
        }

        $value = trim($folder);

        if ($value === '') {
            return WellKnownFolder::INBOX->forDriver('gmail');
        }

        return $value;
    }
}
