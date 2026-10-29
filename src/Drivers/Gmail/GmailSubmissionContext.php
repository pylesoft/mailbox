<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Support\MessageIdentifiers;

final readonly class GmailSubmissionContext
{
    public function __construct(private ?GrantContext $grant) {}

    public function apply(PreparedMessage $message): PreparedMessage
    {
        $context = $message->context;
        if ($context === null) {
            return $message;
        }
        $read = $context->conversation;
        if ($this->grant === null || ! $read->complete || $read->continuation !== null || $read->reason !== null
            || $read->identity->provider !== 'gmail' || $read->identity->formatVersion !== 1
            || ! $read->identity->context->matches($this->grant)
            || preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $read->conversationId) !== 1) {
            throw new UnsupportedCapabilityException('Gmail reply and forward context requires a complete conversation bound to the selected grant and mailbox.');
        }
        $source = null;
        $subjects = [];
        foreach ($read->messages as $candidate) {
            if ($candidate instanceof MessageDto && $candidate->conversationId === $read->conversationId && ! $candidate->isDraft && $candidate->body !== null) {
                $subjects[] = $candidate->subject;
                if ($candidate->id === $context->sourceMessageId) {
                    $source = $candidate;
                }
            }
        }
        if ($source === null) {
            throw new UnsupportedCapabilityException('The selected Gmail source is unavailable in the complete conversation.');
        }
        if ($context->operation === ReplyForwardOperation::Forward) {
            foreach (array_keys($message->headers) as $name) {
                if (in_array(strtolower($name), ['in-reply-to', 'references'], true)) {
                    throw new UnsupportedCapabilityException('Gmail forwards cannot carry reply-only headers.');
                }
            }

            return $message;
        }
        if (! in_array($message->subject, $subjects, true) || $source->internetMessageId === null) {
            throw new UnsupportedCapabilityException('A Gmail reply requires an observed thread subject and the selected source Message-ID.');
        }
        $ids = MessageIdentifiers::parse($source->internetMessageId);
        if (count($ids) !== 1) {
            throw new UnsupportedCapabilityException('The Gmail reply source requires one Message-ID.');
        }
        $references = $this->header($source, 'references');
        $parents = $references === null ? [] : MessageIdentifiers::parse($references);
        if ($references === null && ($inReplyTo = $this->header($source, 'in-reply-to')) !== null) {
            $parentIds = MessageIdentifiers::parse($inReplyTo);
            if (count($parentIds) === 1) {
                $parents = $parentIds;
            }
        }
        $required = ['In-Reply-To' => $ids, 'References' => [...$parents, ...$ids]];
        $headers = $message->headers;
        foreach ($required as $name => $values) {
            $found = false;
            foreach ($headers as $chosenName => $value) {
                if (strcasecmp($chosenName, $name) === 0) {
                    if (MessageIdentifiers::parse($value) !== $values) {
                        throw new UnsupportedCapabilityException('The chosen reply headers do not match the selected Gmail source.');
                    }
                    $found = true;
                }
            }
            if (! $found) {
                $headers[$name] = implode(' ', array_map(static fn (string $id): string => '<'.$id.'>', $values));
            }
        }

        return new PreparedMessage($message->sender, $message->to, $message->subject, $message->correlationId,
            $message->textBody, $message->htmlBody, $message->cc, $message->bcc, $headers, $message->files, $message->context);
    }

    private function header(MessageDto $message, string $name): ?string
    {
        $found = null;
        foreach ($message->raw['payload']['headers'] ?? [] as $header) {
            if (strcasecmp($header['name'], $name) === 0) {
                if ($found !== null) {
                    throw new UnsupportedCapabilityException('The Gmail source contains ambiguous reply headers.');
                }
                $found = $header['value'];
            }
        }

        return $found;
    }
}
