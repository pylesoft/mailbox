<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Pyle\Mailbox\Exceptions\InvalidReceivedHeaderException;
use Pyle\Mailbox\Exceptions\MailboxException;
use stdClass;

final class ConversationPayload
{
    /** @return array<string, mixed> */
    public static function receivedMessage(#[\SensitiveParameter] stdClass $payload): array
    {
        if (property_exists($payload, 'payload') && ! $payload->payload instanceof stdClass) {
            throw new MailboxException('The provider returned an invalid message payload.');
        }
        foreach (['toRecipients', 'ccRecipients', 'bccRecipients', 'replyTo', 'internetMessageHeaders'] as $field) {
            if (property_exists($payload, $field)) {
                self::receivedHeaderCollection($payload->{$field});
            }
        }
        if (($payload->payload ?? null) instanceof stdClass && property_exists($payload->payload, 'headers')) {
            self::receivedHeaderCollection($payload->payload->headers);
            foreach ($payload->payload->headers as $header) {
                if (! $header instanceof stdClass || ! is_string($header->name ?? null) || $header->name === '' || ! is_string($header->value ?? null)) {
                    throw new InvalidReceivedHeaderException('The provider returned an invalid received header.');
                }
            }
        }

        return self::array($payload);
    }

    private static function receivedHeaderCollection(#[\SensitiveParameter] mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidReceivedHeaderException('The provider returned an invalid received header collection.');
        }
    }

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
