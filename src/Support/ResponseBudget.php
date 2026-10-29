<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use InvalidArgumentException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;

final class ResponseBudget
{
    private int $consumed = 0;

    public function __construct(public readonly int $maxBytes)
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('A response byte budget must be positive.');
        }
    }

    public function remaining(): int
    {
        return max(0, $this->maxBytes - $this->consumed);
    }

    public function consume(int $bytes): void
    {
        $this->consumed += $bytes;

        if ($this->consumed > $this->maxBytes) {
            throw new ResponseLimitExceededException('The provider response exceeded the remaining byte budget.');
        }
    }
}
