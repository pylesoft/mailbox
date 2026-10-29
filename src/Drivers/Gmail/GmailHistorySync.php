<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Pyle\Mailbox\DTOs\ChangePage;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\ResponseBudget;

final readonly class GmailHistorySync
{
    public function __construct(private GmailClient $client, private GrantContext $context) {}

    public function page(SyncScope $scope, SyncBudget $budget, SyncCheckpoint $checkpoint, ?SyncContinuation $continuation): ChangePage
    {
        $identity = new SyncIdentity('gmail', $this->context, $scope);
        $checkpoint->identity->assertMatches($identity);
        $state = $this->state($identity, $checkpoint, $continuation);
        $responses = new ResponseBudget($budget->maxResponseBytes);
        $upserts = [];
        $removals = [];

        for ($pages = 0; ; $pages++) {
            $reason = match (true) {
                count($upserts) + count($removals) >= $budget->maxItems => 'item_limit',
                $pages >= $budget->maxPages => 'page_limit',
                $pages >= $budget->maxRequests => 'request_limit',
                $responses->remaining() === 0 => 'response_limit',
                default => null,
            };
            if ($reason !== null) {
                return $this->unfinished($identity, $state, $upserts, $removals, $reason);
            }
            $query = ['startHistoryId' => $checkpoint->position, 'maxResults' => min(500, $budget->maxItems - count($upserts) - count($removals))];
            if ($state['pageToken'] !== null) {
                $query['pageToken'] = $state['pageToken'];
            }
            try {
                $response = $this->client->getBounded('users/'.rawurlencode($this->context->mailbox).'/history', $responses, $query, $this->context->mailbox);
            } catch (ResponseLimitExceededException) {
                return $this->unfinished($identity, $state, $upserts, $removals, 'response_limit');
            } catch (ResourceNotFoundException) {
                return new ChangePage(upserts: $upserts, removals: $removals, resetRequired: true, reason: 'history_expired');
            }
            $next = $response['nextPageToken'] ?? null;
            if (array_key_exists('nextPageToken', $response) && (! is_string($next) || trim($next) === '')) {
                return $this->unfinished($identity, $state, $upserts, $removals, 'provider_progress_missing');
            }
            if ($next !== null && ($next === $state['pageToken'] || in_array(hash('sha256', $next), $state['seenTokens'], true))) {
                return $this->unfinished($identity, $state, $upserts, $removals, 'provider_progress_repeated');
            }
            $position = $response['historyId'] ?? null;
            if (! is_string($position) || preg_match('/^[0-9]+$/D', $position) !== 1 || ! $this->atLeast($position, $checkpoint->position)) {
                return $this->unfinished($identity, $state, $upserts, $removals, 'provider_progress_missing');
            }
            $changes = $this->changes($response, $position);
            if ($changes === null) {
                return $this->unfinished($identity, $state, $upserts, $removals, 'provider_progress_missing');
            }
            $nextUpserts = array_values(array_unique([...array_diff($upserts, $changes['removals']), ...$changes['upserts']]));
            $nextRemovals = array_values(array_unique([...array_diff($removals, $changes['upserts']), ...$changes['removals']]));
            if (count($nextUpserts) + count($nextRemovals) > $budget->maxItems || count($response['history'] ?? []) > $query['maxResults']) {
                return $this->unfinished($identity, $state, $upserts, $removals, 'provider_item_limit');
            }
            $upserts = $nextUpserts;
            $removals = $nextRemovals;
            $state['seenTokens'][] = hash('sha256', $state['pageToken'] ?? '');
            $state['pageToken'] = $next;

            if ($state['pageToken'] === null) {
                return new ChangePage(upserts: $upserts, removals: $removals, checkpoint: new SyncCheckpoint($identity, $position));
            }
        }
    }

    /** @return array{baseline: string, pageToken: ?string, seenTokens: list<string>} */
    private function state(SyncIdentity $identity, SyncCheckpoint $checkpoint, ?SyncContinuation $continuation): array
    {
        if (preg_match('/^[0-9]+$/D', $checkpoint->position) !== 1) {
            throw new InvalidSyncStateException('The Gmail checkpoint does not contain a valid history position.');
        }
        if ($continuation === null) {
            return ['baseline' => $checkpoint->position, 'pageToken' => null, 'seenTokens' => []];
        }
        $continuation->identity->assertMatches($identity);
        $state = $continuation->position;
        if ($continuation->phase !== 'gmail.history'
            || ($state['baseline'] ?? null) !== $checkpoint->position
            || ! array_key_exists('pageToken', $state)
            || ($state['pageToken'] !== null && (! is_string($state['pageToken']) || trim($state['pageToken']) === ''))
            || ! is_array($state['seenTokens'] ?? null)
            || ! array_is_list($state['seenTokens'])) {
            throw new InvalidSyncStateException('The Gmail history continuation is malformed or belongs to a different round.');
        }
        foreach ($state['seenTokens'] as $token) {
            if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                throw new InvalidSyncStateException('The Gmail history continuation contains invalid progress evidence.');
            }
        }

        /** @var array{baseline: string, pageToken: ?string, seenTokens: list<string>} $state */
        return $state;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return ?array{upserts: list<string>, removals: list<string>}
     */
    private function changes(array $response, string $position): ?array
    {
        $history = array_key_exists('history', $response) ? $response['history'] : [];
        if (! is_array($history) || ! array_is_list($history)) {
            throw new ApiRequestException('Gmail returned an invalid history list.');
        }
        $upserts = [];
        $removals = [];
        foreach ($history as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || preg_match('/^[0-9]+$/D', $entry['id']) !== 1) {
                throw new ApiRequestException('Gmail returned an invalid history record.');
            }
            if (! $this->atLeast($position, $entry['id'])) {
                return null;
            }
            $specific = [];
            foreach (['messagesAdded', 'labelsAdded', 'labelsRemoved', 'messagesDeleted'] as $type) {
                foreach ($this->ids($entry, $type, true) as $id) {
                    $specific[$id] = true;
                    if ($type === 'messagesDeleted') {
                        unset($upserts[$id]);
                        $removals[$id] = $id;
                    } else {
                        unset($removals[$id]);
                        $upserts[$id] = $id;
                    }
                }
            }
            foreach ($this->ids($entry, 'messages', false) as $id) {
                if (! isset($specific[$id])) {
                    unset($removals[$id]);
                    $upserts[$id] = $id;
                }
            }
        }

        return ['upserts' => array_values($upserts), 'removals' => array_values($removals)];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function ids(array $entry, string $type, bool $nested): array
    {
        $items = array_key_exists($type, $entry) ? $entry[$type] : [];
        if (! is_array($items) || ! array_is_list($items)) {
            throw new ApiRequestException('Gmail returned an invalid history change list.');
        }
        $ids = [];
        foreach ($items as $item) {
            $message = $nested && is_array($item) ? ($item['message'] ?? null) : $item;
            if (! is_array($message) || ! is_string($message['id'] ?? null) || trim($message['id']) === '') {
                throw new ApiRequestException('Gmail returned a history message without a valid ID.');
            }
            $ids[] = $message['id'];
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $upserts
     * @param  list<string>  $removals
     */
    private function unfinished(SyncIdentity $identity, array $state, array $upserts, array $removals, string $reason): ChangePage
    {
        return new ChangePage(upserts: $upserts, removals: $removals, continuation: new SyncContinuation($identity, 'gmail.history', $state), reason: $reason);
    }

    private function atLeast(string $position, string $previous): bool
    {
        $position = ltrim($position, '0');
        $previous = ltrim($previous, '0');

        return strlen($position) > strlen($previous) || (strlen($position) === strlen($previous) && strcmp($position, $previous) >= 0);
    }
}
