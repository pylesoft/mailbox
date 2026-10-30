<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use JsonException;
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
use Pyle\Mailbox\Support\ConversationPayload;
use Pyle\Mailbox\Support\ResponseBudget;

/**
 * @phpstan-type FolderProgress array{folderId: string, nextLink: ?string, deltaLink: ?string, seenTokens: list<string>}
 * @phpstan-type EnumerationProgress array{folderId: ?string, nextLink: ?string, seenTokens: list<string>}
 * @phpstan-type SyncState array{baseline: ?string, enumeration: list<EnumerationProgress>, folders: list<FolderProgress>}
 * @phpstan-type FolderChanges list<array{folderId: string, upserts: list<string>, removals: list<string>}>
 */
final readonly class MsGraphBoundedSync
{
    public function __construct(private GraphClient $client, private GrantContext $context) {}

    public function page(SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncCheckpoint $checkpoint, #[\SensitiveParameter] ?SyncContinuation $continuation): ChangePage
    {
        $identity = new SyncIdentity('ms-graph', $this->context, $scope);
        $checkpoint?->identity->assertMatches($identity);
        $previous = $this->checkpointFolders($identity, $checkpoint);
        $state = $this->state($identity, $checkpoint, $continuation, $previous);
        $responses = new ResponseBudget($budget->maxResponseBytes);
        $changes = [];
        $items = 0;

        for ($pages = 0; ; $pages++) {
            $active = null;
            foreach ($state['folders'] as $index => $folder) {
                if ($folder['deltaLink'] === null) {
                    $active = $index;
                    break;
                }
            }
            if ($active === null && $state['enumeration'] === []) {
                return $this->result($identity, $state, $changes);
            }
            $reason = match (true) {
                $items >= $budget->maxItems => 'item_limit',
                $pages >= $budget->maxPages => 'page_limit',
                $pages >= $budget->maxRequests => 'request_limit',
                $responses->remaining() === 0 => 'response_limit',
                default => null,
            };
            if ($reason !== null) {
                return $this->result($identity, $state, $changes, $reason);
            }
            if ($state['enumeration'] !== []) {
                try {
                    $reason = $this->enumerate($state, $previous, $budget->maxItems - $items, $responses, $items);
                } catch (ResponseLimitExceededException) {
                    $reason = 'response_limit';
                }
                if ($reason !== null) {
                    return $this->result($identity, $state, $changes, $reason);
                }

                continue;
            }
            if ($active === null) {
                throw new InvalidSyncStateException('Graph progress has no unfinished folder to read.');
            }
            $folder = $state['folders'][$active];
            $base = $this->folderEndpoint($folder['folderId']);
            $endpoint = $folder['nextLink'] ?? $base;
            $remaining = $budget->maxItems - $items;
            try {
                $payload = $this->client->getBoundedObject($endpoint, $responses, $folder['nextLink'] === null ? ['$select' => 'id', '$top' => min(1000, $remaining)] : [], $this->context->mailbox);
            } catch (ResponseLimitExceededException) {
                return $this->result($identity, $state, $changes, 'response_limit');
            } catch (ApiRequestException $e) {
                if ($e->status === 410 || $e->providerCode === 'syncStateNotFound') {
                    return $this->result($identity, $state, $changes, 'delta_expired', reset: true);
                }
                throw $e;
            }
            $values = $payload->value ?? null;
            if (! is_array($values) || ! array_is_list($values)) {
                throw new ApiRequestException('Graph returned a missing or malformed delta collection.');
            }
            foreach ($values as $value) {
                if (! $value instanceof \stdClass) {
                    throw new ApiRequestException('Graph returned a malformed delta entry.');
                }
                if (property_exists($value, '@removed') && ! $value->{'@removed'} instanceof \stdClass) {
                    throw new ApiRequestException('Graph returned malformed folder-removal evidence.');
                }
            }
            $response = ConversationPayload::array($payload);
            $values = $response['value'];
            $hasNext = array_key_exists('@odata.nextLink', $response);
            if ($hasNext === array_key_exists('@odata.deltaLink', $response)) {
                return $this->result($identity, $state, $changes, 'provider_progress_missing');
            }
            $rawLink = $response[$hasNext ? '@odata.nextLink' : '@odata.deltaLink'];
            if (! is_string($rawLink) || trim($rawLink) === '') {
                return $this->result($identity, $state, $changes, 'provider_progress_missing');
            }
            $link = $this->client->continuationEndpoint($rawLink, $base, $this->context->mailbox);
            if (! $this->hasToken($link, [$hasNext ? 'skiptoken' : 'deltatoken'])) {
                return $this->result($identity, $state, $changes, 'provider_progress_missing');
            }
            if ($hasNext && ($link === $endpoint || in_array(hash('sha256', $link), $folder['seenTokens'], true))) {
                return $this->result($identity, $state, $changes, 'provider_progress_repeated');
            }
            if (count($values) > $remaining) {
                return $this->result($identity, $state, $changes, 'provider_item_limit');
            }
            $changeIndex = array_search($folder['folderId'], array_column($changes, 'folderId'), true);
            $change = $changeIndex === false
                ? ['folderId' => $folder['folderId'], 'upserts' => [], 'removals' => []]
                : $changes[$changeIndex];
            foreach ($values as $value) {
                if (! is_array($value) || ! is_string($value['id'] ?? null) || trim($value['id']) === '') {
                    throw new ApiRequestException('Graph returned a delta entry without a valid message ID.');
                }
                if (array_key_exists('@removed', $value)) {
                    if (! is_array($value['@removed'])) {
                        throw new ApiRequestException('Graph returned malformed folder-removal evidence.');
                    }
                    $change['removals'][] = $value['id'];
                } else {
                    $change['upserts'][] = $value['id'];
                }
            }
            $change['upserts'] = array_values(array_unique($change['upserts']));
            $change['removals'] = array_values(array_unique($change['removals']));
            if ($changeIndex === false) {
                $changes[] = $change;
            } else {
                array_splice($changes, $changeIndex, 1, [$change]);
            }
            $items += count($values);
            $folder['seenTokens'][] = hash('sha256', $endpoint);
            if ($hasNext) {
                $folder['nextLink'] = $link;
            } else {
                $folder['deltaLink'] = $link;
                $folder['nextLink'] = null;
            }
            $state['folders'][$active] = $folder;
        }
    }

    /**
     * @param  list<array{folderId: string, deltaLink: string}>  $previous
     * @return SyncState
     */
    private function state(SyncIdentity $identity, #[\SensitiveParameter] ?SyncCheckpoint $checkpoint, #[\SensitiveParameter] ?SyncContinuation $continuation, #[\SensitiveParameter] array $previous): array
    {
        if ($continuation !== null) {
            $continuation->identity->assertMatches($identity);
            $state = $continuation->position;
            if ($continuation->phase !== 'ms-graph.delta'
                || ! array_key_exists('baseline', $state)
                || $state['baseline'] !== $checkpoint?->position
                || ! is_array($state['folders'] ?? null) || ! array_is_list($state['folders'])
                || ! is_array($state['enumeration'] ?? null) || ! array_is_list($state['enumeration'])) {
                throw new InvalidSyncStateException('The Graph continuation belongs to another round.');
            }
            $ids = [];
            $unfinished = $state['enumeration'] !== [];
            foreach ($state['folders'] as &$folder) {
                if (! is_array($folder) || ! is_string($folder['folderId'] ?? null) || trim($folder['folderId']) === ''
                    || in_array($folder['folderId'], $ids, true)
                    || ! array_key_exists('nextLink', $folder) || ! array_key_exists('deltaLink', $folder)
                    || ($folder['nextLink'] !== null && $folder['deltaLink'] !== null)
                    || ! $this->validTokens($folder['seenTokens'] ?? null)) {
                    throw new InvalidSyncStateException('The Graph continuation contains invalid folder progress.');
                }
                $ids[] = $folder['folderId'];
                $base = $this->folderEndpoint($folder['folderId']);
                $folder['nextLink'] = $this->stateLink($folder['nextLink'], $base, ['skiptoken', 'deltatoken']);
                $folder['deltaLink'] = $this->stateLink($folder['deltaLink'], $base, ['deltatoken']);
                $unfinished = $unfinished || $folder['deltaLink'] === null;
            }
            unset($folder);
            $enumerated = [];
            foreach ($state['enumeration'] as &$progress) {
                if (! is_array($progress) || ! array_key_exists('folderId', $progress)
                    || ($progress['folderId'] !== null && ! in_array($progress['folderId'], $ids, true))
                    || in_array($progress['folderId'], $enumerated, true)
                    || ! array_key_exists('nextLink', $progress) || ! $this->validTokens($progress['seenTokens'] ?? null)) {
                    throw new InvalidSyncStateException('The Graph continuation contains invalid hierarchy progress.');
                }
                $enumerated[] = $progress['folderId'];
                $progress['nextLink'] = $this->stateLink($progress['nextLink'], $this->enumerationEndpoint($progress['folderId']));
            }
            unset($progress);
            if (! $unfinished || ($identity->scope->folderId !== null && ($state['enumeration'] !== [] || $ids !== [$identity->scope->folderId]))) {
                throw new InvalidSyncStateException('The Graph continuation does not contain unfinished work in the selected scope.');
            }

            /** @var SyncState $state */
            return $state;
        }

        return [
            'baseline' => $checkpoint?->position,
            'enumeration' => $identity->scope->folderId === null ? [['folderId' => null, 'nextLink' => null, 'seenTokens' => []]] : [],
            'folders' => $identity->scope->folderId === null ? [] : [$this->newFolder($identity->scope->folderId, $previous)],
        ];
    }

    /**
     * @param  SyncState  $state
     * @param  list<array{folderId: string, deltaLink: string}>  $previous
     */
    private function enumerate(#[\SensitiveParameter] array &$state, #[\SensitiveParameter] array $previous, int $remaining, ResponseBudget $responses, int &$items): ?string
    {
        $progress = $state['enumeration'][0];
        $base = $this->enumerationEndpoint($progress['folderId']);
        $endpoint = $progress['nextLink'] ?? $base;
        $payload = $this->client->getBoundedObject($endpoint, $responses, $progress['nextLink'] === null ? ['$select' => 'id', '$top' => min(1000, $remaining), 'includeHiddenFolders' => 'true'] : [], $this->context->mailbox);
        $values = $payload->value ?? null;
        if (! is_array($values) || ! array_is_list($values)) {
            throw new ApiRequestException('Graph returned a missing or malformed folder collection.');
        }
        $response = ConversationPayload::array($payload);
        $values = $response['value'];
        $next = array_key_exists('@odata.nextLink', $response)
            ? $this->client->continuationEndpoint($response['@odata.nextLink'], $base, $this->context->mailbox)
            : null;
        if ($next !== null && ($next === $endpoint || in_array(hash('sha256', $next), $progress['seenTokens'], true))) {
            return 'provider_progress_repeated';
        }
        if (count($values) > $remaining) {
            return 'provider_item_limit';
        }
        foreach ($values as $value) {
            if (! is_array($value) || ! is_string($value['id'] ?? null) || trim($value['id']) === '') {
                throw new ApiRequestException('Graph returned a folder without a valid ID.');
            }
            if (! in_array($value['id'], array_column($state['folders'], 'folderId'), true)) {
                $state['folders'][] = $this->newFolder($value['id'], $previous);
                $state['enumeration'][] = ['folderId' => $value['id'], 'nextLink' => null, 'seenTokens' => []];
            }
        }
        $items += count($values);
        if ($next === null) {
            array_shift($state['enumeration']);
        } else {
            $progress['nextLink'] = $next;
            $progress['seenTokens'][] = hash('sha256', $endpoint);
            $state['enumeration'][0] = $progress;
        }

        return null;
    }

    /**
     * @param  list<array{folderId: string, deltaLink: string}>  $previous
     * @return FolderProgress
     */
    private function newFolder(string $folderId, #[\SensitiveParameter] array $previous): array
    {
        $next = null;
        foreach ($previous as $folder) {
            if ($folder['folderId'] === $folderId) {
                $next = $this->client->continuationEndpoint($folder['deltaLink'], $this->folderEndpoint($folderId), $this->context->mailbox);
                break;
            }
        }

        return ['folderId' => $folderId, 'nextLink' => $next, 'deltaLink' => null, 'seenTokens' => []];
    }

    private function folderEndpoint(string $folderId): string
    {
        return 'users/'.rawurlencode($this->context->mailbox).'/mailFolders/'.rawurlencode($folderId).'/messages/delta';
    }

    private function enumerationEndpoint(?string $folderId): string
    {
        return 'users/'.rawurlencode($this->context->mailbox).'/mailFolders'.($folderId === null ? '' : '/'.rawurlencode($folderId).'/childFolders');
    }

    /** @return list<array{folderId: string, deltaLink: string}> */
    private function checkpointFolders(SyncIdentity $identity, #[\SensitiveParameter] ?SyncCheckpoint $checkpoint): array
    {
        if ($checkpoint === null) {
            return [];
        }
        try {
            $stored = json_decode($checkpoint->position, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidSyncStateException('The Graph checkpoint does not contain valid progress.');
        }
        if (! is_array($stored) || ! is_array($stored['folders'] ?? null) || ! array_is_list($stored['folders'])) {
            throw new InvalidSyncStateException('The Graph checkpoint does not contain a folder list.');
        }
        $folders = [];
        $ids = [];
        foreach ($stored['folders'] as $folder) {
            if (! is_array($folder) || ! is_string($folder['folderId'] ?? null) || trim($folder['folderId']) === ''
                || in_array($folder['folderId'], $ids, true) || ! is_string($folder['deltaLink'] ?? null)) {
                throw new InvalidSyncStateException('The Graph checkpoint contains invalid folder progress.');
            }
            $ids[] = $folder['folderId'];
            $folders[] = ['folderId' => $folder['folderId'], 'deltaLink' => $this->stateLink($folder['deltaLink'], $this->folderEndpoint($folder['folderId']), ['deltatoken'])];
        }
        if ($identity->scope->folderId !== null && $ids !== [$identity->scope->folderId]) {
            throw new InvalidSyncStateException('The Graph checkpoint covers a different folder scope.');
        }

        /** @var list<array{folderId: string, deltaLink: string}> $folders */
        return $folders;
    }

    /** @param list<string> $tokens */
    private function stateLink(#[\SensitiveParameter] mixed $link, string $base, array $tokens = []): ?string
    {
        if ($link === null) {
            return null;
        }
        try {
            $target = $this->client->continuationEndpoint($link, $base, $this->context->mailbox);
        } catch (ApiRequestException) {
            throw new InvalidSyncStateException('The Graph progress link does not match the selected resource.');
        }
        if ($tokens !== [] && ! $this->hasToken($target, $tokens)) {
            throw new InvalidSyncStateException('The Graph progress link does not contain the required token.');
        }

        return $target;
    }

    /** @param list<string> $names */
    private function hasToken(#[\SensitiveParameter] string $link, array $names): bool
    {
        $query = (string) parse_url($link, PHP_URL_QUERY);
        foreach ($names as $name) {
            if (preg_match('/(?:^|&)(?:\$|%24)'.preg_quote($name, '/').'=[^&]+/i', $query) === 1) {
                return true;
            }
        }

        return false;
    }

    private function validTokens(mixed $tokens): bool
    {
        if (! is_array($tokens) || ! array_is_list($tokens)) {
            return false;
        }
        foreach ($tokens as $token) {
            if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  SyncState  $state
     * @param  FolderChanges  $changes
     */
    private function result(SyncIdentity $identity, #[\SensitiveParameter] array $state, array $changes, ?string $reason = null, bool $reset = false): ChangePage
    {
        $folders = array_map(static fn (array $folder): array => ['folderId' => $folder['folderId'], 'deltaLink' => $folder['deltaLink']], $state['folders']);

        return new ChangePage(
            upserts: array_values(array_unique(array_merge(...array_column($changes, 'upserts')))),
            removals: array_values(array_unique(array_merge(...array_column($changes, 'removals')))),
            continuation: $reason === null || $reset ? null : new SyncContinuation($identity, 'ms-graph.delta', $state),
            checkpoint: $reason === null ? new SyncCheckpoint($identity, json_encode(['folders' => $folders], JSON_THROW_ON_ERROR)) : null,
            resetRequired: $reset,
            reason: $reason,
            folderChanges: $changes,
            coveredFolders: array_column($state['folders'], 'folderId'),
        );
    }
}
