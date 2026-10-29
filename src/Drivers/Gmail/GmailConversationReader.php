<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

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
use Pyle\Mailbox\Support\GmailMime;

final readonly class GmailConversationReader
{
    public function __construct(private GmailClient $client, private GrantContext $context) {}

    public function read(string $conversationId, SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation, ?BodyReadLimits $limits): ConversationRead
    {
        $identity = new SyncIdentity('gmail', $this->context, $scope);
        if (trim($conversationId) === '') {
            throw new InvalidArgumentException('A conversation ID must be non-empty.');
        }
        $state = $this->state($identity, $conversationId, $continuation);
        $limits ??= new BodyReadLimits;
        $work = new ConversationReadBudget($budget, $limits);
        $reader = new GmailMessageReader($this->client, $this->context->mailbox, $limits, $work->responses, fn () => $work->request(), retry: false);
        $messages = [];
        $files = [];
        $available = [];
        $reason = null;
        try {
            if ($state['pending'] === null) {
                $work->request(page: true);
                $thread = ConversationPayload::array($this->client->getBoundedObject('users/'.rawurlencode($this->context->mailbox).'/threads/'.rawurlencode($conversationId), $work->responses, ['format' => 'full'], $this->context->mailbox));
                if (($thread['id'] ?? null) !== $conversationId || ! is_array($thread['messages'] ?? null) || ! array_is_list($thread['messages']) || $thread['messages'] === []) {
                    throw new MailboxException('Gmail returned an invalid conversation.');
                }
                $ids = [];
                foreach ($thread['messages'] as $message) {
                    if (! is_array($message) || ! is_string($message['id'] ?? null) || trim($message['id']) === '' || in_array($message['id'], $ids, true)) {
                        throw new MailboxException('Gmail returned missing or duplicate conversation message identities.');
                    }
                    $ids[] = $message['id'];
                }
                $state['pending'] = $ids;
                $available = $thread['messages'];
            }
            while ($state['pending'] !== []) {
                if (count($messages) >= $budget->maxItems) {
                    $reason = 'item_limit';
                    break;
                }
                $id = $state['pending'][0];
                if ($available !== []) {
                    $message = array_shift($available);
                } else {
                    $work->request();
                    $message = ConversationPayload::array($this->client->getBoundedObject('users/'.rawurlencode($this->context->mailbox).'/messages/'.rawurlencode($id), $work->responses, ['format' => 'full'], $this->context->mailbox));
                }
                if (($message['id'] ?? null) !== $id || ($message['threadId'] ?? null) !== $conversationId || ! is_array($message['payload'] ?? null)
                    || ! is_string($message['payload']['mimeType'] ?? null) || $message['payload']['mimeType'] === '') {
                    throw new MailboxException('Gmail returned incomplete conversation content.');
                }
                $message = $reader->hydrateMessage($message);
                $mapped = MessageDto::fromGmail($message);
                $messageFiles = [];
                foreach (GmailMime::files($message['payload']) as $file) {
                    $attachment = AttachmentDto::fromGmail($file);
                    if ($attachment->id === '' || ! is_int($file['body']['size'] ?? null) || $file['body']['size'] < 0) {
                        throw new MailboxException('Gmail returned incomplete conversation file metadata.');
                    }
                    $messageFiles[] = ['messageId' => $id, 'attachment' => $attachment];
                }
                if ($mapped->body === null && $messageFiles === []) {
                    throw new MailboxException('Gmail returned no complete conversation body or file content.');
                }
                $messages[] = $mapped;
                array_push($files, ...$messageFiles);
                array_shift($state['pending']);
            }
        } catch (AuthenticationException|GrantAccessDeniedException $exception) {
            throw $exception;
        } catch (ResponseLimitExceededException) {
            $reason = $work->reason ?? ($work->responses->remaining() === 0 ? 'response_limit' : 'content_limit');
        } catch (MailboxAccessDeniedException|ResourceNotFoundException) {
            $reason = 'scope_unavailable';
        } catch (ApiRequestException|ProviderServerException|RateLimitException) {
            $reason = 'provider_interrupted';
        } catch (MailboxException) {
            $reason = 'content_incomplete';
        }

        return new ConversationRead($identity, $conversationId, $messages, $files, complete: $reason === null,
            continuation: $reason === null ? null : new SyncContinuation($identity, 'gmail.conversation', $state), reason: $reason);
    }

    /** @return array{conversationId: string, pending: ?list<string>} */
    private function state(SyncIdentity $identity, string $conversationId, #[\SensitiveParameter] ?SyncContinuation $continuation): array
    {
        if ($continuation === null) {
            return ['conversationId' => $conversationId, 'pending' => null];
        }
        $continuation->identity->assertMatches($identity);
        $state = $continuation->position;
        if ($continuation->phase !== 'gmail.conversation' || ($state['conversationId'] ?? null) !== $conversationId || ! array_key_exists('pending', $state)
            || ($state['pending'] !== null && (! is_array($state['pending']) || ! array_is_list($state['pending']) || $state['pending'] === []))) {
            throw new InvalidSyncStateException('The continuation does not match this Gmail conversation read.');
        }
        foreach ($state['pending'] ?? [] as $id) {
            if (! is_string($id) || trim($id) === '') {
                throw new InvalidSyncStateException('The Gmail conversation continuation has an invalid message identity.');
            }
        }

        /** @var array{conversationId: string, pending: ?list<string>} $state */
        return $state;
    }
}
