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
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\ResponseBudget;

final readonly class GmailInitialSync
{
    public function __construct(private GmailClient $client, private GrantContext $context) {}

    public function page(SyncScope $scope, SyncBudget $budget, ?SyncContinuation $continuation): ChangePage
    {
        $identity = new SyncIdentity('gmail', $this->context, $scope);
        $state = $this->state($identity, $continuation);
        $responses = new ResponseBudget($budget->maxResponseBytes);
        $upserts = [];
        $pages = 0;
        $requests = 0;
        $endpoint = 'users/'.rawurlencode($this->context->mailbox);

        while (true) {
            $reason = match (true) {
                count($upserts) >= $budget->maxItems => 'item_limit',
                $pages >= $budget->maxPages => 'page_limit',
                $requests >= $budget->maxRequests => 'request_limit',
                $responses->remaining() === 0 => 'response_limit',
                default => null,
            };

            if ($reason !== null) {
                return $this->unfinished($identity, $state, $upserts, $reason);
            }

            try {
                $requests++;

                if ($state['baseline'] === null) {
                    $profile = $this->client->getBounded($endpoint.'/profile', $responses, mailbox: $this->context->mailbox);
                    $baseline = $profile['historyId'] ?? null;

                    if (! is_string($baseline) || preg_match('/^[0-9]+$/D', $baseline) !== 1) {
                        throw new ApiRequestException('Gmail returned a missing or invalid initial history baseline.');
                    }

                    $state['baseline'] = $baseline;

                    continue;
                }

                $query = ['maxResults' => min(500, $budget->maxItems - count($upserts)), 'includeSpamTrash' => true];
                if ($scope->folderId !== null) {
                    $query['labelIds'] = [$scope->folderId];
                }
                if ($scope->importSince !== null) {
                    $query['q'] = 'after:'.$scope->importSince->getTimestamp();
                }
                if ($state['pageToken'] !== null) {
                    $query['pageToken'] = $state['pageToken'];
                }

                $response = $this->client->getBounded($endpoint.'/messages', $responses, $query, $this->context->mailbox);
            } catch (ResponseLimitExceededException) {
                return $this->unfinished($identity, $state, $upserts, 'response_limit');
            }

            $pages++;
            $next = $response['nextPageToken'] ?? null;
            if (array_key_exists('nextPageToken', $response) && (! is_string($next) || trim($next) === '')) {
                return $this->unfinished($identity, $state, $upserts, 'provider_progress_missing');
            }

            $signature = hash('sha256', $state['pageToken'] ?? '');
            if ($next !== null && ($next === $state['pageToken'] || in_array(hash('sha256', $next), $state['seenTokens'], true))) {
                return $this->unfinished($identity, $state, $upserts, 'provider_progress_repeated');
            }

            $summaries = array_key_exists('messages', $response) ? $response['messages'] : [];
            if (! is_array($summaries) || ! array_is_list($summaries)) {
                throw new ApiRequestException('Gmail returned an invalid initial message list.');
            }
            if (count($summaries) > $query['maxResults']) {
                return $this->unfinished($identity, $state, $upserts, 'provider_item_limit');
            }

            $ids = [];
            foreach ($summaries as $summary) {
                if (! is_array($summary) || ! is_string($summary['id'] ?? null) || trim($summary['id']) === '') {
                    throw new ApiRequestException('Gmail returned a message without a valid ID.');
                }
                $ids[] = $summary['id'];
            }

            $upserts = array_values(array_unique([...$upserts, ...$ids]));
            $state['seenTokens'][] = $signature;
            $state['pageToken'] = $next;

            if ($next === null) {
                return new ChangePage(upserts: $upserts, checkpoint: new SyncCheckpoint($identity, $state['baseline']));
            }
        }
    }

    /** @return array{baseline: ?string, pageToken: ?string, seenTokens: list<string>} */
    private function state(SyncIdentity $identity, ?SyncContinuation $continuation): array
    {
        if ($continuation === null) {
            return ['baseline' => null, 'pageToken' => null, 'seenTokens' => []];
        }

        $continuation->identity->assertMatches($identity);
        $state = $continuation->position;
        if ($continuation->phase !== 'gmail.initial'
            || ! array_key_exists('baseline', $state)
            || ($state['baseline'] !== null && (! is_string($state['baseline']) || preg_match('/^[0-9]+$/D', $state['baseline']) !== 1))
            || ! array_key_exists('pageToken', $state)
            || ($state['pageToken'] !== null && (! is_string($state['pageToken']) || trim($state['pageToken']) === ''))
            || ! $this->stringList($state['seenTokens'] ?? null)
            || ($state['baseline'] === null && ($state['pageToken'] !== null || $state['seenTokens'] !== []))) {
            throw new InvalidSyncStateException('The Gmail initial continuation is malformed or belongs to a different operation.');
        }

        /** @var array{baseline: ?string, pageToken: ?string, seenTokens: list<string>} $state */
        return $state;
    }

    private function stringList(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $upserts
     */
    private function unfinished(SyncIdentity $identity, array $state, array $upserts, string $reason): ChangePage
    {
        return new ChangePage(upserts: $upserts, continuation: new SyncContinuation($identity, 'gmail.initial', $state), reason: $reason);
    }
}
