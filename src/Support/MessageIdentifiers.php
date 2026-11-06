<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\ExceptionInterface;

final class MessageIdentifiers
{
    /** @return list<string> */
    public static function parse(string $value, ?ResponseBudget $budget = null): array
    {
        if (strlen($value) > 65_536) {
            throw new InvalidArgumentException('Message identifier headers exceed their supported byte limit.');
        }
        $budget?->assertMemoryAvailable(strlen($value) * 8 + 262_144);
        $unfolded = preg_replace('/\r\n(?=[ \t])/', '', $value);
        if ($unfolded === null
            || preg_match('/\A[ \t]*(?:<[^<>\x00-\x20\x7f-\xff]+>[ \t]*)+\z/', $unfolded) !== 1) {
            throw new InvalidArgumentException('Message identifiers require complete ASCII angle-bracketed IDs.');
        }
        preg_match_all('/<([^<>]+)>/', $unfolded, $matches);
        foreach ($matches[1] as $id) {
            try {
                new Address($id);
            } catch (ExceptionInterface) {
                throw new InvalidArgumentException('The message identifier is invalid or unsupported.');
            }
        }

        return $matches[1];
    }
}
