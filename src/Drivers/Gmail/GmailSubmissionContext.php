<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use InvalidArgumentException;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Support\MessageIdentifiers;
use Pyle\Mailbox\Support\ResponseBudget;

final readonly class GmailSubmissionContext
{
    public function __construct(private ?GrantContext $grant, private ResponseBudget $budget) {}

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
        $subjectObserved = false;
        foreach ($read->messages as $candidate) {
            if ($candidate->conversationId === $read->conversationId && ! $candidate->isDraft && $candidate->body !== null) {
                $subjectObserved = $subjectObserved || $candidate->subject === $message->subject;
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
                if (! is_string($name)) {
                    throw new InvalidArgumentException('Prepared headers require supported field names and text values.');
                }
                if (in_array(strtolower($name), ['in-reply-to', 'references'], true)) {
                    throw new UnsupportedCapabilityException('Gmail forwards cannot carry reply-only headers.');
                }
            }

            return $message;
        }
        if (! $subjectObserved || $source->internetMessageId === null) {
            throw new UnsupportedCapabilityException('A Gmail reply requires an observed thread subject and the selected source Message-ID.');
        }
        $ids = MessageIdentifiers::parse($source->internetMessageId, $this->budget);
        $sourceId = $this->header($source, 'message-id');
        if (count($ids) !== 1 || $sourceId === null || MessageIdentifiers::parse($sourceId, $this->budget) !== $ids) {
            throw new UnsupportedCapabilityException('The Gmail reply source requires one Message-ID.');
        }
        $references = $this->header($source, 'references');
        $parents = $references === null ? [] : MessageIdentifiers::parse($references, $this->budget);
        if ($references === null && ($inReplyTo = $this->header($source, 'in-reply-to')) !== null) {
            $parentIds = MessageIdentifiers::parse($inReplyTo, $this->budget);
            if (count($parentIds) === 1) {
                $parents = $parentIds;
            }
        }
        $required = ['In-Reply-To' => $ids, 'References' => [...$parents, ...$ids]];
        $headers = $message->headers;
        foreach ($required as $name => $values) {
            $found = false;
            foreach ($headers as $chosenName => $value) {
                if (! is_string($chosenName) || ! is_string($value)) {
                    throw new InvalidArgumentException('Prepared headers require supported field names and text values.');
                }
                if (strcasecmp($chosenName, $name) === 0) {
                    if (MessageIdentifiers::parse($value, $this->budget) !== $values) {
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
        $payload = $message->raw['payload'] ?? null;
        $headers = is_array($payload) ? ($payload['headers'] ?? null) : null;
        if (! is_array($headers) || ! array_is_list($headers)) {
            throw new UnsupportedCapabilityException('The Gmail source has unavailable reply headers.');
        }
        foreach ($headers as $header) {
            if (! is_array($header) || ! is_string($header['name'] ?? null) || ! is_string($header['value'] ?? null)) {
                throw new UnsupportedCapabilityException('The Gmail source has incomplete reply headers.');
            }
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
