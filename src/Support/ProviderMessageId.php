<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

final class ProviderMessageId
{
    public static function isValid(#[\SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && ! preg_match('/[\x00-\x1F\x7F]/', $value);
    }
}
