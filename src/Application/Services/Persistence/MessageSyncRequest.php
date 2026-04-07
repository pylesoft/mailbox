<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Pyle\Mailbox\Enums\WellKnownFolder;

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
            return self::normalize(
                filters: $request->filters(),
                runtimeRuleTree: $request->ruleTree(),
                folderReference: $request->folderReference(),
                persistAttachments: $request->persistAttachments(),
                ruleTree: $ruleTree,
            );
        }

        if (! is_array($request)) {
            return new self;
        }

        return self::normalize(
            filters: isset($request['filters']) && is_array($request['filters'])
                ? $request['filters']
                : [],
            runtimeRuleTree: $request['rule_tree'] ?? null,
            folderReference: self::resolveFolderReference($request),
            persistAttachments: ! array_key_exists('include_attachments', $request)
                || (bool) $request['include_attachments'],
            ruleTree: $ruleTree,
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

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function normalize(
        array $filters,
        mixed $runtimeRuleTree,
        ?string $folderReference,
        bool $persistAttachments,
        MessageSyncRuleTree $ruleTree,
    ): self {
        $resolvedRuleTree = $ruleTree->extract($runtimeRuleTree, $filters['rule_tree'] ?? null);
        unset($filters['rule_tree']);

        return new self(
            filters: $filters,
            ruleTree: $resolvedRuleTree,
            folderReference: self::normalizeStoredFolderReference(
                self::preferredFolderReference($folderReference, $filters),
            ),
            persistAttachments: $persistAttachments,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function preferredFolderReference(?string $folderReference, array $filters): ?string
    {
        if (is_string($folderReference) && trim($folderReference) !== '') {
            return $folderReference;
        }

        return self::legacyFolderReference($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function legacyFolderReference(array $filters): ?string
    {
        if (isset($filters['mail_folder_id']) && is_string($filters['mail_folder_id'])) {
            return $filters['mail_folder_id'];
        }

        return null;
    }

    private static function normalizeStoredFolderReference(?string $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $trimmed = trim($reference);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);
        if ($normalized === 'inbox' || $normalized === 'wk:inbox') {
            return WellKnownFolder::INBOX->value;
        }

        if (str_starts_with($normalized, 'wk:')) {
            $wellKnown = substr($normalized, 3);

            return $wellKnown !== '' ? $wellKnown : $trimmed;
        }

        return $trimmed;
    }
}
