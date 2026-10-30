<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use InvalidArgumentException;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\ConversationPayload;
use Pyle\Mailbox\Support\ConversationReadBudget;

/** @phpstan-type ConversationState array{conversationId: string, started: bool, nextLink: ?string, seenLinks: list<string>, pending: list<string>, seenIds: list<string>, files: list<array<string, mixed>>, filesStarted: bool, fileNext: ?string, fileSeen: list<string>} */
final readonly class MsGraphConversationReader
{
    private const MESSAGE_SELECT = 'id,subject,bodyPreview,body,from,sender,toRecipients,ccRecipients,bccRecipients,replyTo,internetMessageHeaders,receivedDateTime,sentDateTime,isRead,isDraft,hasAttachments,importance,conversationId,internetMessageId,parentFolderId';

    private const FILE_SELECT = 'id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId';

    public function __construct(private GraphClient $client, private GrantContext $context) {}

    public function read(string $conversationId, SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation, ?BodyReadLimits $limits): ConversationRead
    {
        if (trim($conversationId) === '') {
            throw new InvalidArgumentException('A conversation ID must be non-empty.');
        }
        $identity = new SyncIdentity('ms-graph', $this->context, $scope);
        $base = 'users/'.rawurlencode($this->context->mailbox).($scope->folderId === null ? '' : '/mailFolders/'.rawurlencode($scope->folderId)).'/messages';
        $state = $this->state($identity, $conversationId, $base, $continuation);
        $work = new ConversationReadBudget($budget, $limits ?? new BodyReadLimits);
        $messages = [];
        $files = [];
        $available = [];
        $reason = null;
        try {
            $work->content(parts: count($state['files']));
            while (true) {
                if ($state['pending'] === []) {
                    if ($state['started'] && $state['nextLink'] === null) {
                        break;
                    }
                    if (count($messages) >= $budget->maxItems) {
                        $reason = 'item_limit';
                        break;
                    }
                    $endpoint = $state['nextLink'] ?? $base;
                    $page = $this->collection($endpoint, $state['nextLink'] === null ? [
                        '$filter' => "conversationId eq '".str_replace("'", "''", $conversationId)."'",
                        '$select' => self::MESSAGE_SELECT,
                        '$top' => min(1000, $budget->maxItems - count($messages)),
                    ] : [], $work);
                    $next = $this->next($page, $base, $endpoint, $state['seenLinks']);
                    $pending = [];
                    $available = [];
                    foreach ($page['value'] as $value) {
                        $this->assertMessage($value, $conversationId);
                        if (! in_array($value['id'], $state['seenIds'], true) && ! in_array($value['id'], $pending, true)) {
                            $pending[] = $value['id'];
                            $available[] = $value;
                        }
                    }
                    $state['pending'] = $pending;
                    array_push($state['seenIds'], ...$pending);
                    $state['seenLinks'][] = hash('sha256', $endpoint);
                    $state['nextLink'] = $next;
                    $state['started'] = true;

                    continue;
                }
                if (count($messages) >= $budget->maxItems) {
                    $reason = 'item_limit';
                    break;
                }
                $id = $state['pending'][0];
                $endpoint = $base.'/'.rawurlencode($id);
                $message = array_shift($available);
                if ($message === null || ! array_key_exists('body', $message)) {
                    $work->request(hydration: true);
                    $message = ConversationPayload::array($this->client->getBoundedObject($endpoint, $work->responses, ['$select' => self::MESSAGE_SELECT], $this->context->mailbox));
                }
                $this->assertMessage($message, $conversationId);
                if ($message['id'] !== $id || ! is_array($message['body'] ?? null)
                    || ! in_array($message['body']['contentType'] ?? null, ['text', 'html'], true) || ! is_string($message['body']['content'] ?? null)) {
                    throw new MailboxException('Graph returned missing or malformed conversation body content.');
                }
                $work->content(strlen($message['body']['content']));
                $mapped = MessageDto::fromMsGraph($message);
                $attachmentBase = $endpoint.'/attachments';
                while (! $state['filesStarted'] || $state['fileNext'] !== null) {
                    $fileEndpoint = $state['fileNext'] ?? $attachmentBase;
                    $page = $this->collection($fileEndpoint, $state['fileNext'] === null ? ['$select' => self::FILE_SELECT] : [], $work, hydration: true);
                    $next = $this->next($page, $attachmentBase, $fileEndpoint, $state['fileSeen']);
                    $metadata = [];
                    foreach ($page['value'] as $value) {
                        $attachment = $this->attachment($value);
                        if (! in_array($attachment->id, array_column($state['files'], 'id'), true)
                            && ! in_array($attachment->id, array_column($metadata, 'id'), true)) {
                            $metadata[] = ['id' => $attachment->id, 'name' => $attachment->name, 'contentType' => $attachment->contentType,
                                'size' => $attachment->size, 'isInline' => $attachment->isInline, 'contentId' => $attachment->contentId];
                        }
                    }
                    $work->content(parts: count($metadata));
                    array_push($state['files'], ...$metadata);
                    $state['fileSeen'][] = hash('sha256', $fileEndpoint);
                    $state['fileNext'] = $next;
                    $state['filesStarted'] = true;
                }
                $messages[] = $mapped;
                foreach ($state['files'] as $metadata) {
                    $files[] = ['messageId' => $id, 'attachment' => $this->attachment($metadata)];
                }
                array_shift($state['pending']);
                $state['files'] = $state['fileSeen'] = [];
                $state['filesStarted'] = false;
                $state['fileNext'] = null;
            }
        } catch (AuthenticationException|GrantAccessDeniedException $exception) {
            throw $exception;
        } catch (ResponseLimitExceededException) {
            $reason = $work->reason ?? 'response_limit';
        } catch (MailboxAccessDeniedException|ResourceNotFoundException) {
            $reason = 'scope_unavailable';
        } catch (ApiRequestException|ProviderServerException|RateLimitException) {
            $reason = 'provider_interrupted';
        } catch (MailboxException) {
            $reason = 'content_incomplete';
        }

        return new ConversationRead($identity, $conversationId, $messages, $files, complete: $reason === null,
            continuation: $reason === null ? null : new SyncContinuation($identity, 'ms-graph.conversation', $state), reason: $reason);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function collection(string $endpoint, array $query, ConversationReadBudget $work, bool $hydration = false): array
    {
        $work->request(page: true, hydration: $hydration);
        $page = $this->client->getBoundedObject($endpoint, $work->responses, $query, $this->context->mailbox);
        if (! is_array($page->value ?? null) || ! array_is_list($page->value)) {
            throw new MailboxException('Graph returned a missing or malformed conversation collection.');
        }

        return ConversationPayload::array($page);
    }

    private function assertMessage(mixed $value, string $conversationId): void
    {
        if (! is_array($value) || ! is_string($value['id'] ?? null) || trim($value['id']) === '' || ($value['conversationId'] ?? null) !== $conversationId) {
            throw new MailboxException('Graph returned a message outside the selected conversation.');
        }
    }

    private function attachment(mixed $value): AttachmentDto
    {
        if (! is_array($value) || ! is_string($value['id'] ?? null) || trim($value['id']) === ''
            || ! is_string($value['name'] ?? null) || ! is_string($value['contentType'] ?? null) || $value['contentType'] === ''
            || ! is_int($value['size'] ?? null) || $value['size'] < 0 || ! is_bool($value['isInline'] ?? null)
            || (isset($value['contentId']) && ! is_string($value['contentId']))) {
            throw new MailboxException('Graph returned incomplete conversation file metadata.');
        }

        return AttachmentDto::fromMsGraph($value);
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<string>  $seen
     */
    private function next(array $page, string $base, string $endpoint, array $seen): ?string
    {
        if (! array_key_exists('@odata.nextLink', $page)) {
            return null;
        }
        $next = $this->client->continuationEndpoint($page['@odata.nextLink'], $base, $this->context->mailbox);
        if ($next === $endpoint || in_array(hash('sha256', $next), $seen, true)) {
            throw new ApiRequestException('Graph conversation paging repeated an unfinished position.');
        }

        return $next;
    }

    /** @return ConversationState */
    private function state(SyncIdentity $identity, string $conversationId, string $base, #[\SensitiveParameter] ?SyncContinuation $continuation): array
    {
        if ($continuation === null) {
            return ['conversationId' => $conversationId, 'started' => false, 'nextLink' => null, 'seenLinks' => [], 'pending' => [], 'seenIds' => [],
                'files' => [], 'filesStarted' => false, 'fileNext' => null, 'fileSeen' => []];
        }
        $continuation->identity->assertMatches($identity);
        $state = $continuation->position;
        if ($continuation->phase !== 'ms-graph.conversation' || ($state['conversationId'] ?? null) !== $conversationId
            || ! is_bool($state['started'] ?? null) || ! is_bool($state['filesStarted'] ?? null)
            || ! array_key_exists('nextLink', $state) || ! array_key_exists('fileNext', $state)
            || ! $this->strings($state['pending'] ?? null) || ! $this->strings($state['seenIds'] ?? null)
            || ! $this->strings($state['seenLinks'] ?? null) || ! $this->strings($state['fileSeen'] ?? null)
            || ! is_array($state['files'] ?? null) || ! array_is_list($state['files'])
            || ($state['pending'] === [] && ($state['files'] !== [] || $state['filesStarted'] || $state['fileNext'] !== null))
            || ($state['started'] && $state['pending'] === [] && $state['nextLink'] === null)
            || (! $state['started'] && ($state['pending'] !== [] || $state['nextLink'] !== null))) {
            throw new InvalidSyncStateException('The continuation does not match this Graph conversation read.');
        }
        try {
            foreach ($state['files'] as $file) {
                $this->attachment($file);
            }
            if ($state['nextLink'] !== null) {
                $state['nextLink'] = $this->client->continuationEndpoint($state['nextLink'], $base, $this->context->mailbox);
            }
            if ($state['fileNext'] !== null) {
                $state['fileNext'] = $this->client->continuationEndpoint($state['fileNext'], $base.'/'.rawurlencode($state['pending'][0]).'/attachments', $this->context->mailbox);
            }
        } catch (MailboxException) {
            throw new InvalidSyncStateException('The Graph conversation continuation contains invalid scoped progress.');
        }

        /** @var ConversationState $state */
        return $state;
    }

    private function strings(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return count(array_unique($values)) === count($values);
    }
}
