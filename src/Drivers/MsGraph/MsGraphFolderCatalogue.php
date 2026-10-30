<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use InvalidArgumentException;
use Pyle\Mailbox\DTOs\FolderCatalogue;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\WellKnownFolder;
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
use Pyle\Mailbox\Support\ResponseBudget;
use stdClass;

/**
 * @phpstan-type CatalogueProgress array{folderId: ?string, nextLink: ?string, seenLinks: list<string>}
 * @phpstan-type CatalogueState array{requested: list<string>, canonical: array<string, string>, enumeration: list<CatalogueProgress>, known: list<array{id: string, parent: string, root: bool}>}
 */
final readonly class MsGraphFolderCatalogue
{
    private const FOLDER_SELECT = 'id,displayName,parentFolderId,childFolderCount,totalItemCount,unreadItemCount,isHidden';

    public function __construct(private GraphClient $client, private GrantContext $context) {}

    /** @param list<WellKnownFolder> $wellKnownFolders */
    public function read(SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation, array $wellKnownFolders): FolderCatalogue
    {
        $requested = $this->aliases($wellKnownFolders);
        $identity = new SyncIdentity('ms-graph', $this->context, new SyncScope);
        $state = $this->state($identity, $continuation, $requested);
        $responses = new ResponseBudget($budget->maxResponseBytes);
        $folders = [];
        $items = 0;
        $reason = null;

        try {
            for ($requests = 0; ; $requests++) {
                $pendingAliases = array_values(array_diff($requested, array_keys($state['canonical'])));
                if ($pendingAliases === [] && $state['enumeration'] === []) {
                    break;
                }
                $reason = match (true) {
                    $items >= $budget->maxItems => 'item_limit',
                    $requests >= $budget->maxRequests => 'request_limit',
                    $requests >= $budget->maxPages => 'page_limit',
                    $responses->remaining() === 0 => 'response_limit',
                    default => null,
                };
                if ($reason !== null) {
                    break;
                }
                if ($pendingAliases !== []) {
                    $alias = $pendingAliases[0];
                    $endpoint = 'users/'.rawurlencode($this->context->mailbox).'/mailFolders/'.FolderIdResolver::resolve(WellKnownFolder::from($alias));
                    $payload = $this->client->getBoundedObject($endpoint, $responses, ['$select' => 'id'], $this->context->mailbox);
                    if (! is_string($payload->id ?? null) || trim($payload->id) === '') {
                        throw new MailboxException('Graph returned a well-known folder without a valid ID.');
                    }
                    $state['canonical'][$alias] = $payload->id;
                    $items++;

                    continue;
                }

                $progress = $state['enumeration'][0];
                $base = $this->endpoint($progress['folderId']);
                $endpoint = $progress['nextLink'] ?? $base;
                $remaining = $budget->maxItems - $items;
                $payload = $this->client->getBoundedObject($endpoint, $responses, $progress['nextLink'] === null
                    ? ['$select' => self::FOLDER_SELECT, '$top' => min(1000, $remaining), 'includeHiddenFolders' => 'true']
                    : [], $this->context->mailbox);
                if (! is_array($payload->value ?? null) || ! array_is_list($payload->value)) {
                    throw new MailboxException('Graph returned a missing or malformed folder catalogue collection.');
                }
                $next = property_exists($payload, '@odata.nextLink')
                    ? $this->client->continuationEndpoint($payload->{'@odata.nextLink'}, $base, $this->context->mailbox)
                    : null;
                if ($next !== null && ($next === $endpoint || in_array(hash('sha256', $next), $progress['seenLinks'], true))) {
                    $reason = 'provider_progress_repeated';
                    break;
                }
                if (count($payload->value) > $remaining) {
                    $reason = 'provider_item_limit';
                    break;
                }
                $page = [];
                foreach ($payload->value as $value) {
                    $page[] = $this->folder($value, $progress['folderId'], $state['canonical']);
                }
                foreach ($page as $folder) {
                    $index = array_search($folder->id, array_column($state['known'], 'id'), true);
                    if ($index !== false) {
                        if ($state['known'][$index]['parent'] !== $folder->parentFolderId
                            || $state['known'][$index]['root'] !== ($progress['folderId'] === null)) {
                            throw new MailboxException('Graph returned conflicting folder catalogue ancestry.');
                        }

                        continue;
                    }
                    $state['known'][] = ['id' => $folder->id, 'parent' => $folder->parentFolderId, 'root' => $progress['folderId'] === null];
                    $folders[] = $folder;
                    if ($folder->childFolderCount > 0) {
                        $state['enumeration'][] = ['folderId' => $folder->id, 'nextLink' => null, 'seenLinks' => []];
                    }
                }
                $items += count($page);
                if ($next === null) {
                    array_shift($state['enumeration']);
                } else {
                    $progress['nextLink'] = $next;
                    $progress['seenLinks'][] = hash('sha256', $endpoint);
                    $state['enumeration'][0] = $progress;
                }
            }
        } catch (AuthenticationException|GrantAccessDeniedException $exception) {
            throw $exception;
        } catch (ResponseLimitExceededException) {
            $reason = 'response_limit';
        } catch (MailboxAccessDeniedException|ResourceNotFoundException) {
            $reason = 'scope_unavailable';
        } catch (ApiRequestException|ProviderServerException|RateLimitException) {
            $reason = 'provider_interrupted';
        } catch (MailboxException) {
            $reason = 'content_incomplete';
        }

        $roots = [];
        foreach ($state['known'] as $folder) {
            if ($folder['root']) {
                $roots[] = $folder['id'];
            }
        }

        return new FolderCatalogue($identity, $folders, $state['canonical'], complete: $reason === null,
            continuation: $reason === null ? null : new SyncContinuation($identity, 'ms-graph.folder-catalogue', $state), reason: $reason, rootFolderIds: $roots);
    }

    /**
     * @param  array<string, string>  $canonical
     */
    private function folder(mixed $value, ?string $parent, array $canonical): FolderDto
    {
        if (! $value instanceof stdClass || ! is_string($value->id ?? null) || trim($value->id) === ''
            || ! is_string($value->displayName ?? null) || ! is_string($value->parentFolderId ?? null) || trim($value->parentFolderId) === ''
            || $value->id === $value->parentFolderId || ($parent !== null && $value->parentFolderId !== $parent)
            || ! is_bool($value->isHidden ?? null)
            || (property_exists($value, '@odata.type') && (! is_string($value->{'@odata.type'}) || trim($value->{'@odata.type'}) === ''))) {
            throw new MailboxException('Graph returned incomplete or out-of-scope folder catalogue metadata.');
        }
        foreach (['childFolderCount', 'totalItemCount', 'unreadItemCount'] as $field) {
            if (! is_int($value->{$field} ?? null) || $value->{$field} < 0) {
                throw new MailboxException('Graph returned malformed folder catalogue counts.');
            }
        }
        $alias = array_search($value->id, $canonical, true);

        return new FolderDto($value->id, $value->displayName, $value->parentFolderId, $value->childFolderCount,
            $value->totalItemCount, $value->unreadItemCount, path: null, wellKnownName: $alias === false ? null : WellKnownFolder::from($alias),
            isHidden: $value->isHidden, providerType: $value->{'@odata.type'} ?? '#microsoft.graph.mailFolder');
    }

    /**
     * @param  array<array-key, mixed>  $folders
     * @return list<string>
     */
    private function aliases(array $folders): array
    {
        if (! array_is_list($folders)) {
            throw new InvalidArgumentException('Well-known folders must be an enum list.');
        }
        $aliases = [];
        foreach ($folders as $folder) {
            if (! $folder instanceof WellKnownFolder || in_array($folder->value, $aliases, true)) {
                throw new InvalidArgumentException('Well-known folders must be a distinct enum list.');
            }
            $aliases[] = $folder->value;
        }

        return $aliases;
    }

    private function endpoint(?string $folderId): string
    {
        return 'users/'.rawurlencode($this->context->mailbox).'/mailFolders'.($folderId === null ? '' : '/'.rawurlencode($folderId).'/childFolders');
    }

    /**
     * @param  list<string>  $requested
     * @return CatalogueState
     */
    private function state(SyncIdentity $identity, #[\SensitiveParameter] ?SyncContinuation $continuation, array $requested): array
    {
        if ($continuation === null) {
            return ['requested' => $requested, 'canonical' => [], 'enumeration' => [['folderId' => null, 'nextLink' => null, 'seenLinks' => []]], 'known' => []];
        }
        $continuation->identity->assertMatches($identity);
        $state = $continuation->position;
        if ($continuation->phase !== 'ms-graph.folder-catalogue' || ($state['requested'] ?? null) !== $requested
            || ! is_array($state['canonical'] ?? null) || ! is_array($state['enumeration'] ?? null) || ! array_is_list($state['enumeration'])
            || ! is_array($state['known'] ?? null) || ! array_is_list($state['known'])) {
            throw new InvalidSyncStateException('The continuation does not match this folder catalogue.');
        }
        foreach ($state['canonical'] as $alias => $id) {
            if (! in_array($alias, $requested, true) || ! is_string($id) || trim($id) === '') {
                throw new InvalidSyncStateException('The folder catalogue continuation contains invalid canonical identities.');
            }
        }
        $ids = [];
        foreach ($state['known'] as $folder) {
            if (! is_array($folder) || ! is_string($folder['id'] ?? null) || trim($folder['id']) === ''
                || ! is_string($folder['parent'] ?? null) || trim($folder['parent']) === '' || $folder['id'] === $folder['parent']
                || ! is_bool($folder['root'] ?? null) || (! $folder['root'] && ! in_array($folder['parent'], $ids, true))
                || in_array($folder['id'], $ids, true)) {
                throw new InvalidSyncStateException('The folder catalogue continuation contains invalid observed folders.');
            }
            $ids[] = $folder['id'];
        }
        if (($state['known'] !== [] && count($state['canonical']) !== count($requested))
            || $state['enumeration'] === []) {
            throw new InvalidSyncStateException('The folder catalogue continuation does not contain unfinished work.');
        }
        $parents = [];
        foreach ($state['enumeration'] as &$progress) {
            if (! is_array($progress) || ! array_key_exists('folderId', $progress)
                || ($progress['folderId'] !== null && ! in_array($progress['folderId'], $ids, true))
                || in_array($progress['folderId'], $parents, true) || ! array_key_exists('nextLink', $progress)
                || ! is_array($progress['seenLinks'] ?? null) || ! array_is_list($progress['seenLinks'])) {
                throw new InvalidSyncStateException('The folder catalogue continuation contains invalid hierarchy progress.');
            }
            $parents[] = $progress['folderId'];
            foreach ($progress['seenLinks'] as $hash) {
                if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new InvalidSyncStateException('The folder catalogue continuation contains invalid page identities.');
                }
            }
            if ($progress['nextLink'] !== null) {
                try {
                    $progress['nextLink'] = $this->client->continuationEndpoint($progress['nextLink'], $this->endpoint($progress['folderId']), $this->context->mailbox);
                } catch (MailboxException) {
                    throw new InvalidSyncStateException('The folder catalogue continuation contains invalid scoped progress.');
                }
            }
        }
        unset($progress);

        /** @var CatalogueState $state */
        return $state;
    }
}
