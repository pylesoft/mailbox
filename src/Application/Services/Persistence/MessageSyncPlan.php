<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Support\MessageMatcher;

final class MessageSyncPlan
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     */
    public function __construct(
        private readonly array $filters,
        private readonly array $ruleTree,
        private readonly string|WellKnownFolder|null $folderReference,
        private readonly ?MessageMatcher $matcher,
        private readonly bool $requiresAttachmentMetadata,
        private readonly bool $persistAttachments,
    ) {}

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->filters;
    }

    /** @return array<string, mixed> */
    public function ruleTree(): array
    {
        return $this->ruleTree;
    }

    public function folderReference(): string|WellKnownFolder|null
    {
        return $this->folderReference;
    }

    public function matcher(): ?MessageMatcher
    {
        return $this->matcher;
    }

    public function requiresAttachmentMetadata(): bool
    {
        return $this->requiresAttachmentMetadata;
    }

    public function persistAttachments(): bool
    {
        return $this->persistAttachments;
    }
}
