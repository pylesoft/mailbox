<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;

final class SubmissionBody implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private StreamInterface $stream, private readonly ResponseBudget $budget) {}

    public function read(int $length): string
    {
        if ($length < 0) {
            return $this->stream->read($length);
        }
        $length = min($length, 8192);
        $this->budget->assertMemoryAvailable($length * 2 + 65_536);

        return $this->stream->read($length);
    }

    public function getContents(): string
    {
        $size = $this->getSize();
        $remaining = $size === null ? null : max(0, $size - $this->tell());
        if ($remaining === null || $remaining > intdiv(PHP_INT_MAX - 65_536, 2)) {
            throw new ResponseLimitExceededException('The submission body cannot be buffered within its allocation budget.');
        }
        $this->budget->assertMemoryAvailable($remaining * 2 + 65_536);

        return $this->stream->getContents();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['byteLength' => $this->getSize()];
    }
}
