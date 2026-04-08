<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Events\DeltaSyncCompleted;
use Pyle\Mailbox\Events\DeltaSyncStarted;
use Pyle\Mailbox\Events\DeltaTokenExpired;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\DeltaTokenExpiredException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;

class GmailDeltaSync
{
    public function __construct(
        private readonly GmailClient $client,
    ) {}

    public function syncFolder(string $mailbox, string $folderId, ?string $deltaToken = null): DeltaResultDto
    {
        $startedAt = microtime(true);

        Event::dispatch(new DeltaSyncStarted('gmail', $mailbox, $folderId));

        if ($deltaToken === null || trim($deltaToken) === '') {
            return $this->performInitialSync($mailbox, $folderId, $startedAt);
        }

        try {
            $changes = $this->collectHistoryChanges($mailbox, $folderId, $deltaToken);
        } catch (ApiRequestException|ResourceNotFoundException $e) {
            if ($e instanceof ResourceNotFoundException || $e->status === 404) {
                return $this->expiredDeltaResult(
                    $mailbox,
                    $folderId,
                    new DeltaTokenExpiredException(
                        mailbox: $mailbox,
                        folderId: $folderId,
                        message: 'Gmail delta token expired. A full sync is required.',
                        previous: $e,
                    ),
                );
            }

            throw $e;
        }

        $createdIds = $changes['createdIds'];
        $updatedIds = $changes['updatedIds'];
        $deleted = $this->collectDeletedMessages($changes['deletedIds'], $createdIds, $updatedIds);
        $created = $this->fetchMessages($mailbox, array_keys($createdIds));
        $updated = $this->fetchMessages($mailbox, array_keys($updatedIds));

        return $this->completeIncrementalSync(
            mailbox: $mailbox,
            folderId: $folderId,
            created: $created,
            updated: $updated,
            deleted: $deleted,
            latestHistoryId: $changes['latestHistoryId'],
            startedAt: $startedAt,
        );
    }

    private function performInitialSync(string $mailbox, string $folderId, float $startedAt): DeltaResultDto
    {
        $messages = (new GmailMessageQuery($this->client, $mailbox))->inFolder($folderId)->get();
        $historyId = $this->currentHistoryId($mailbox);

        Event::dispatch(new DeltaSyncCompleted(
            driver: 'gmail',
            mailbox: $mailbox,
            folder: $folderId,
            created: $messages->count(),
            updated: 0,
            deleted: 0,
        ));

        $this->logInfo('Gmail initial delta sync completed', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'created' => $messages->count(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return new DeltaResultDto(
            created: $messages,
            updated: collect(),
            deleted: collect(),
            deltaLink: $historyId,
            fullSyncRequired: false,
        );
    }

    /**
     * @return array{
     *     createdIds: array<string, true>,
     *     updatedIds: array<string, true>,
     *     deletedIds: array<string, true>,
     *     latestHistoryId: ?string
     * }
     */
    private function collectHistoryChanges(string $mailbox, string $folderId, string $deltaToken): array
    {
        /** @var array<string, true> $createdIds */
        $createdIds = [];
        /** @var array<string, true> $updatedIds */
        $updatedIds = [];
        /** @var array<string, true> $deletedIds */
        $deletedIds = [];

        $nextPageToken = null;
        $latestHistoryId = null;

        do {
            $response = $this->fetchHistoryPage($mailbox, $folderId, $deltaToken, $nextPageToken);

            foreach ((array) ($response['history'] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $this->mergeHistoryEntryIds($entry, $createdIds, $updatedIds, $deletedIds);
            }

            $latestHistoryId = isset($response['historyId']) ? (string) $response['historyId'] : $latestHistoryId;
            $nextPageToken = isset($response['nextPageToken']) && (string) $response['nextPageToken'] !== ''
                ? (string) $response['nextPageToken']
                : null;
        } while ($nextPageToken !== null);

        return [
            'createdIds' => $createdIds,
            'updatedIds' => $updatedIds,
            'deletedIds' => $deletedIds,
            'latestHistoryId' => $latestHistoryId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchHistoryPage(
        string $mailbox,
        string $folderId,
        string $deltaToken,
        ?string $nextPageToken,
    ): array {
        $query = [
            'startHistoryId' => $deltaToken,
            'labelId' => $folderId,
            'historyTypes' => ['messageAdded', 'messageDeleted', 'labelAdded', 'labelRemoved'],
            'maxResults' => 100,
        ];

        if ($nextPageToken !== null) {
            $query['pageToken'] = $nextPageToken;
        }

        return $this->client->get(
            sprintf('users/%s/history', rawurlencode($mailbox)),
            $query,
            $mailbox,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, true>  $createdIds
     * @param  array<string, true>  $updatedIds
     * @param  array<string, true>  $deletedIds
     */
    private function mergeHistoryEntryIds(
        array $entry,
        array &$createdIds,
        array &$updatedIds,
        array &$deletedIds,
    ): void {
        $this->addHistoryMessageIds($entry, 'messagesAdded', $createdIds);
        $this->addHistoryMessageIds($entry, 'messagesDeleted', $deletedIds);
        $this->addHistoryMessageIds($entry, 'labelsAdded', $updatedIds);
        $this->addHistoryMessageIds($entry, 'labelsRemoved', $updatedIds);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, true>  $bucket
     */
    private function addHistoryMessageIds(array $entry, string $historyKey, array &$bucket): void
    {
        foreach ((array) ($entry[$historyKey] ?? []) as $item) {
            if (! is_array($item) || ! is_array($item['message'] ?? null)) {
                continue;
            }

            $id = (string) ($item['message']['id'] ?? '');
            if ($id !== '') {
                $bucket[$id] = true;
            }
        }
    }

    /**
     * @param  array<string, true>  $deletedIds
     * @param  array<string, true>  $createdIds
     * @param  array<string, true>  $updatedIds
     * @return Collection<int, string>
     */
    private function collectDeletedMessages(array $deletedIds, array &$createdIds, array &$updatedIds): Collection
    {
        $deleted = collect();

        foreach (array_keys($deletedIds) as $deletedId) {
            $deletedId = (string) $deletedId;
            $deleted->push($deletedId);
            unset($createdIds[$deletedId], $updatedIds[$deletedId]);
        }

        return $deleted;
    }

    private function expiredDeltaResult(
        string $mailbox,
        string $folderId,
        ?DeltaTokenExpiredException $exception = null,
    ): DeltaResultDto {
        Event::dispatch(new DeltaTokenExpired('gmail', $mailbox, $folderId));

        $this->logInfo('Gmail delta token expired', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'error' => $exception?->getMessage(),
        ]);

        return new DeltaResultDto(
            created: collect(),
            updated: collect(),
            deleted: collect(),
            deltaLink: null,
            fullSyncRequired: true,
        );
    }

    /**
     * @param  Collection<int, MessageDto>  $created
     * @param  Collection<int, MessageDto>  $updated
     * @param  Collection<int, string>  $deleted
     */
    private function completeIncrementalSync(
        string $mailbox,
        string $folderId,
        Collection $created,
        Collection $updated,
        Collection $deleted,
        ?string $latestHistoryId,
        float $startedAt,
    ): DeltaResultDto {
        Event::dispatch(new DeltaSyncCompleted(
            driver: 'gmail',
            mailbox: $mailbox,
            folder: $folderId,
            created: $created->count(),
            updated: $updated->count(),
            deleted: $deleted->count(),
        ));

        $this->logInfo('Gmail delta sync completed', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'created' => $created->count(),
            'updated' => $updated->count(),
            'deleted' => $deleted->count(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return new DeltaResultDto(
            created: $created,
            updated: $updated,
            deleted: $deleted,
            deltaLink: $latestHistoryId ?? $this->currentHistoryId($mailbox),
            fullSyncRequired: false,
        );
    }

    /**
     * @param  array<int, string>  $messageIds
     * @return Collection<int, MessageDto>
     */
    private function fetchMessages(string $mailbox, array $messageIds): Collection
    {
        return collect($messageIds)
            ->map(function (string $messageId) use ($mailbox): ?MessageDto {
                if (trim($messageId) === '') {
                    return null;
                }

                try {
                    $payload = $this->client->get(
                        sprintf('users/%s/messages/%s', rawurlencode($mailbox), rawurlencode($messageId)),
                        ['format' => 'full'],
                        $mailbox,
                    );
                } catch (ApiRequestException|ResourceNotFoundException $e) {
                    if ($e instanceof ResourceNotFoundException || $e->status === 404) {
                        return null;
                    }

                    throw $e;
                }

                return MessageDto::fromGmail($payload);
            })
            ->filter(fn (?MessageDto $message): bool => $message instanceof MessageDto)
            ->values();
    }

    private function currentHistoryId(string $mailbox): ?string
    {
        $profile = $this->client->get(sprintf('users/%s/profile', rawurlencode($mailbox)), mailbox: $mailbox);
        $historyId = (string) ($profile['historyId'] ?? '');

        return $historyId !== '' ? $historyId : null;
    }

    /** @param array<string, mixed> $context */
    private function logInfo(string $message, array $context = []): void
    {
        Log::channel((string) config('mailbox.log_channel', 'stack'))->info($message, $context);
    }
}
