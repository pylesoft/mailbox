<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\Exceptions\MailboxException;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\Part\AddressPart;
use ZBateson\MailMimeParser\Header\SubjectHeader;
use ZBateson\MbWrapper\MbWrapper;

final class ReceivedHeaders
{
    public static function text(string $value): string
    {
        self::validateEncoding($value);
        $header = new SubjectHeader('Subject', $value);
        $decoded = $header->getDecodedValue();

        if ($header->hasAnyErrors()) {
            throw new MailboxException('Malformed or unsupported received text header.');
        }

        return $decoded;
    }

    /** @return array<EmailAddressDto> */
    public static function addresses(string $value): array
    {
        self::validateEncoding($value);
        self::validateDelimiters($value);
        $header = new AddressHeader('To', $value);

        if ($header->hasAnyErrors()) {
            throw new MailboxException('Malformed received address header.');
        }

        foreach ($header->getGroups() as $group) {
            if ($group->hasErrors(true)) {
                throw new MailboxException('Malformed received address group.');
            }
        }

        foreach ($header->getAddresses() as $address) {
            if ($address->hasAnyErrors(true) || preg_match('/^(?:"(?:[^"\\\\]|\\\\.)+"|[^\s@"<>(),;:\[\]]+)@(?:[^\s@"<>(),;:\[\]]+|\[[^\[\]\s]+\])$/u', $address->getEmail()) !== 1) {
                throw new MailboxException('Malformed received email address.');
            }
        }

        return array_map(
            static fn (AddressPart $address): EmailAddressDto => new EmailAddressDto($address->getName(), $address->getEmail()),
            $header->getAddresses(),
        );
    }

    private static function validateEncoding(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|\r(?!\n[ \t])|(?<!\r)\n|\n(?![ \t])/', $value) === 1) {
            throw new MailboxException('Malformed received header bytes or folding.');
        }

        preg_match_all('/=\?([^?\s]+)\?([bBqQ])\?([^?\s]*)\?=/', $value, $words, PREG_SET_ORDER);
        $remainder = preg_replace('/=\?([^?\s]+)\?([bBqQ])\?([^?\s]*)\?=/', '', $value);
        if (str_contains($remainder ?? '', '=?')) {
            throw new MailboxException('Malformed or truncated received encoded word.');
        }

        foreach ($words as $word) {
            $encoded = $word[3];
            if (strtoupper($word[2]) === 'B') {
                $bytes = base64_decode($encoded, true);
                if ($bytes === false || strlen($encoded) % 4 !== 0) {
                    throw new MailboxException('Malformed received encoded word base64.');
                }
            } else {
                if (preg_match('/=(?![0-9a-fA-F]{2})|[^\x21-\x7e]/', $encoded) === 1) {
                    throw new MailboxException('Malformed received encoded word quoted-printable.');
                }
                $bytes = quoted_printable_decode(str_replace('_', ' ', $encoded));
            }

            $charset = explode('*', $word[1], 2)[0];
            try {
                $converter = new MbWrapper;
                $valid = $converter->checkEncoding($bytes, $charset);
                $decoded = $converter->convert($bytes, $charset, 'UTF-8');
            } catch (\Throwable) {
                throw new MailboxException('Unsupported received header charset.');
            }
            if (! $valid || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1) {
                throw new MailboxException('Malformed received encoded word bytes.');
            }
        }
    }

    private static function validateDelimiters(string $value): void
    {
        // The parser recovers unfinished delimiters; incoming mapping must report that lost structure.
        $stack = [];
        $pairs = ['"' => '"', '(' => ')', '<' => '>', '[' => ']', ':' => ';'];
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];
            $current = end($stack);
            if ($character === '\\') {
                $index++;
                if ($index >= $length) {
                    throw new MailboxException('Truncated received address escape.');
                }

                continue;
            }
            if ($current === '"' || $current === '[' || $current === '(') {
                if ($character === $pairs[$current]) {
                    array_pop($stack);
                } elseif ($current === '(' && $character === '(') {
                    $stack[] = '(';
                }

                continue;
            }
            if (isset($pairs[$character])) {
                $stack[] = $character;
            } elseif (in_array($character, [')', '>', ']', ';'], true)) {
                if ($current === false || $pairs[$current] !== $character) {
                    throw new MailboxException('Malformed received address delimiters.');
                }
                array_pop($stack);
            }
        }
        if ($stack !== []) {
            throw new MailboxException('Truncated received address header.');
        }
    }
}
