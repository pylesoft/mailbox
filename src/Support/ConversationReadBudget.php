<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;

final class ConversationReadBudget
{
    public readonly ResponseBudget $responses;

    public ?string $reason = null;

    private int $requests = 0;

    private int $pages = 0;

    private int $hydrations = 0;

    private int $contentBytes = 0;

    private int $parts = 0;

    public function __construct(private readonly SyncBudget $budget, private readonly BodyReadLimits $limits)
    {
        $this->responses = new ResponseBudget(min($budget->maxResponseBytes, $limits->maxResponseBytes));
    }

    public function request(bool $page = false, bool $hydration = false): void
    {
        $this->reason = match (true) {
            $this->requests >= $this->budget->maxRequests => 'request_limit',
            $page && $this->pages >= $this->budget->maxPages => 'page_limit',
            $hydration && $this->hydrations >= $this->limits->maxHydrations => 'hydration_limit',
            $this->responses->remaining() === 0 => 'response_limit',
            default => null,
        };
        if ($this->reason !== null) {
            throw new ResponseLimitExceededException('The conversation read exhausted its operation budget.');
        }
        $this->requests++;
        $this->pages += (int) $page;
        $this->hydrations += (int) $hydration;
    }

    public function content(int $bytes = 0, int $parts = 0): void
    {
        $this->reason = match (true) {
            $bytes > $this->limits->maxBodyBytes - $this->contentBytes => 'content_limit',
            $parts > $this->limits->maxParts - $this->parts => 'part_limit',
            default => null,
        };
        if ($this->reason !== null) {
            throw new ResponseLimitExceededException('The conversation content exceeded its operation budget.');
        }
        $this->contentBytes += $bytes;
        $this->parts += $parts;
    }
}
