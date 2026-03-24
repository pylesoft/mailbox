<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

final class MessageSyncRequest
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     */
    public function __construct(
        private readonly array $filters = [],
        private readonly array $ruleTree = [],
        private readonly ?string $folderReference = null,
        private readonly bool $persistAttachments = true,
    ) {}

    /**
     * @param  MessageSyncRequest|array<string, mixed>|null  $request
     */
    public static function from(MessageSyncRequest|array|null $request, MessageSyncRuleTree $ruleTree): self
    {
        if ($request instanceof self) {
            return $request;
        }

        if (! is_array($request)) {
            return new self;
        }

        $filters = isset($request['filters']) && is_array($request['filters'])
            ? $request['filters']
            : [];

        $resolvedRuleTree = $ruleTree->extract($request['rule_tree'] ?? null, $filters['rule_tree'] ?? null);
        unset($filters['rule_tree']);

        return new self(
            filters: $filters,
            ruleTree: $resolvedRuleTree,
            folderReference: self::resolveFolderReference($request),
            persistAttachments: ! array_key_exists('include_attachments', $request)
                || (bool) $request['include_attachments'],
        );
    }

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

    public function folderReference(): ?string
    {
        return $this->folderReference;
    }

    public function persistAttachments(): bool
    {
        return $this->persistAttachments;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private static function resolveFolderReference(array $request): ?string
    {
        if (isset($request['folder']) && is_string($request['folder'])) {
            return $request['folder'];
        }

        if (isset($request['folder_reference']) && is_string($request['folder_reference'])) {
            return $request['folder_reference'];
        }

        return null;
    }
}
