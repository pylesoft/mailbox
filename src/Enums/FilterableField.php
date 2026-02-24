<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum FilterableField: string
{
    case SUBJECT = 'subject';
    case FROM_ADDRESS = 'from.address';
    case FROM_NAME = 'from.name';
    case SENDER_ADDRESS = 'sender.address';
    case TO_ADDRESS = 'toRecipients.address';
    case CC_ADDRESS = 'ccRecipients.address';
    case RECEIVED_AT = 'receivedAt';
    case IS_READ = 'isRead';
    case IS_DRAFT = 'isDraft';
    case HAS_ATTACHMENTS = 'hasAttachments';
    case IMPORTANCE = 'importance';
    case BODY_PREVIEW = 'bodyPreview';
    case ATTACHMENT_COUNT = 'attachmentCount';
    case ATTACHMENT_NAME = 'attachmentName';
    case ATTACHMENT_CONTENT_TYPE = 'attachmentContentType';
    case ATTACHMENT_SIZE = 'attachmentSize';

    /** @return array<MatchOperator> */
    public function operators(): array
    {
        return match ($this) {
            self::SUBJECT => [
                MatchOperator::EQUALS,
                MatchOperator::CONTAINS,
                MatchOperator::STARTS_WITH,
                MatchOperator::ENDS_WITH,
                MatchOperator::MATCHES_REGEX,
            ],
            self::FROM_ADDRESS, self::SENDER_ADDRESS => [
                MatchOperator::EQUALS,
                MatchOperator::CONTAINS,
                MatchOperator::ENDS_WITH,
            ],
            self::FROM_NAME, self::TO_ADDRESS, self::CC_ADDRESS, self::ATTACHMENT_CONTENT_TYPE => [
                MatchOperator::EQUALS,
                MatchOperator::CONTAINS,
            ],
            self::BODY_PREVIEW => [
                MatchOperator::CONTAINS,
                MatchOperator::MATCHES_REGEX,
            ],
            self::ATTACHMENT_NAME => [
                MatchOperator::EQUALS,
                MatchOperator::CONTAINS,
                MatchOperator::STARTS_WITH,
                MatchOperator::ENDS_WITH,
                MatchOperator::MATCHES_REGEX,
            ],
            self::RECEIVED_AT => [
                MatchOperator::BEFORE,
                MatchOperator::AFTER,
                MatchOperator::BETWEEN,
            ],
            self::IS_READ, self::IS_DRAFT, self::HAS_ATTACHMENTS, self::IMPORTANCE => [
                MatchOperator::EQUALS,
            ],
            self::ATTACHMENT_COUNT, self::ATTACHMENT_SIZE => [
                MatchOperator::EQUALS,
                MatchOperator::GREATER_THAN,
                MatchOperator::LESS_THAN,
                MatchOperator::BETWEEN,
            ],
        };
    }

    public function valueType(): string
    {
        return match ($this) {
            self::IS_READ, self::IS_DRAFT, self::HAS_ATTACHMENTS => 'boolean',
            self::ATTACHMENT_COUNT, self::ATTACHMENT_SIZE => 'integer',
            self::RECEIVED_AT => 'datetime',
            self::IMPORTANCE => 'enum',
            default => 'string',
        };
    }

    public function isServerPushable(string $driver = 'ms-graph'): bool
    {
        if ($driver !== 'ms-graph') {
            return false;
        }

        return match ($this) {
            self::SUBJECT,
            self::RECEIVED_AT,
            self::IS_READ,
            self::IS_DRAFT,
            self::HAS_ATTACHMENTS,
            self::IMPORTANCE,
            self::FROM_ADDRESS => true,
            default => false,
        };
    }
}
