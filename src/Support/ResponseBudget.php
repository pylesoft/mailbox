<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use InvalidArgumentException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;

final class ResponseBudget
{
    private int $consumed = 0;

    private readonly int $memoryBaseline;

    public function __construct(public readonly int $maxBytes, public readonly ?int $maxMemoryBytes = null)
    {
        if ($maxBytes < 1 || ($maxMemoryBytes !== null && $maxMemoryBytes < 1)) {
            throw new InvalidArgumentException('Response byte and memory budgets must be positive.');
        }
        $this->memoryBaseline = memory_get_usage();
    }

    public function remaining(): int
    {
        return max(0, $this->maxBytes - $this->consumed);
    }

    public function consume(int $bytes): void
    {
        if ($bytes < 0 || $bytes > $this->remaining()) {
            $this->consumed = $this->maxBytes;
            throw new ResponseLimitExceededException('The provider response exceeded the remaining byte budget.');
        }
        $this->consumed += $bytes;
    }

    public function assertMemoryAvailable(int $additionalBytes): void
    {
        if ($this->maxMemoryBytes === null) {
            return;
        }
        $growth = max(0, memory_get_usage() - $this->memoryBaseline);
        if ($additionalBytes < 0 || $growth > $this->maxMemoryBytes || $additionalBytes > $this->maxMemoryBytes - $growth) {
            throw new ResponseLimitExceededException('The provider read exceeded its memory allocation budget.');
        }
        $processLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($processLimit > 0 && $additionalBytes > max(0, $processLimit - memory_get_usage(true) - 65536)) {
            throw new ResponseLimitExceededException('The provider read cannot safely allocate within the PHP memory limit.');
        }
    }

    public function assertJsonDecodeMemory(#[\SensitiveParameter] string $json): void
    {
        if ($this->maxMemoryBytes === null) {
            return;
        }
        $length = strlen($json);
        if ($length > intdiv(PHP_INT_MAX - 65536, 2)) {
            throw new ResponseLimitExceededException('The provider JSON allocation estimate exceeded its limit.');
        }
        $reservation = $length * 2 + 65536;
        $this->assertMemoryAvailable($reservation);
        $inString = false;

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $json[$offset];
            if ($inString) {
                if ($character === '\\') {
                    $offset++;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($character === '"') {
                $inString = true;
            } elseif (str_contains(" \t\r\n]}", $character)) {
                continue;
            }
            if ($reservation > PHP_INT_MAX - 2048) {
                throw new ResponseLimitExceededException('The provider JSON allocation estimate exceeded its limit.');
            }
            // shortcut: native allocation estimates are verified on 64-bit PHP 8.4; remeasure for other runtime guarantees.
            $reservation += 2048;
            $this->assertMemoryAvailable($reservation);
        }
    }
}
