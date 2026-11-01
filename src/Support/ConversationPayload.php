<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Pyle\Mailbox\Exceptions\MailboxException;
use stdClass;

final class ConversationPayload
{
    /** @return array<string, mixed> */
    public static function array(#[\SensitiveParameter] stdClass $payload): array
    {
        $data = [];
        foreach (get_object_vars($payload) as $key => $value) {
            if (in_array($key, ['messages', 'parts', 'headers', 'internetMessageHeaders', 'labelIds', 'toRecipients', 'ccRecipients', 'bccRecipients', 'replyTo', 'history', 'messagesAdded', 'messagesDeleted', 'labelsAdded', 'labelsRemoved'], true)
                && (! is_array($value) || ! array_is_list($value))) {
                throw new MailboxException('The provider returned an invalid collection.');
            }
            $data[$key] = self::value($value);
        }

        return $data;
    }

    private static function value(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return self::array($value);
        }

        return is_array($value) ? array_map(self::value(...), $value) : $value;
    }
}
