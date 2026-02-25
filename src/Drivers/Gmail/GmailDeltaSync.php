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

class GmailDeltaSync
{
    public function __construct(
        private readonly GmailClient $client,
    ) {}

    public function syncFolder(string $mailbox, string $folderId, ?string $deltaToken = null): DeltaResultDto
    {
        $startedAt = microtime(true);

        Event::dispatch(new DeltaSyncStarted('gmail', $mailbox, $folderId));

        $created = collect();
        $updated = collect();
        $deleted = collect();

        if ($deltaToken === null || trim($deltaToken) === '') {
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

        /** @var array<string, true> $createdIds */
        $createdIds = [];
        /** @var array<string, true> $updatedIds */
        $updatedIds = [];
        /** @var array<string, true> $deletedIds */
        $deletedIds = [];

        $nextPageToken = null;
        $latestHistoryId = null;

        try {
            do {
                $query = [
                    'startHistoryId' => $deltaToken,
                    'labelId' => $folderId,
                    'historyTypes' => ['messageAdded', 'messageDeleted', 'labelAdded', 'labelRemoved'],
                    'maxResults' => 100,
                ];

                if ($nextPageToken !== null) {
                    $query['pageToken'] = $nextPageToken;
                }

                $response = $this->client->get(sprintf('users/%s/history', rawurlencode($mailbox)), $query, $mailbox);

                foreach ((array) ($response['history'] ?? []) as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    foreach ((array) ($entry['messagesAdded'] ?? []) as $item) {
                        if (! is_array($item) || ! is_array($item['message'] ?? null)) {
                            continue;
                        }

                        $id = (string) ($item['message']['id'] ?? '');
                        if ($id !== '') {
                            $createdIds[$id] = true;
                        }
                    }

                    foreach ((array) ($entry['messagesDeleted'] ?? []) as $item) {
                        if (! is_array($item) || ! is_array($item['message'] ?? null)) {
                            continue;
                        }

                        $id = (string) ($item['message']['id'] ?? '');
                        if ($id !== '') {
                            $deletedIds[$id] = true;
                        }
                    }

                    foreach ((array) ($entry['labelsAdded'] ?? []) as $item) {
                        if (! is_array($item) || ! is_array($item['message'] ?? null)) {
                            continue;
                        }

                        $id = (string) ($item['message']['id'] ?? '');
                        if ($id !== '') {
                            $updatedIds[$id] = true;
                        }
                    }

                    foreach ((array) ($entry['labelsRemoved'] ?? []) as $item) {
                        if (! is_array($item) || ! is_array($item['message'] ?? null)) {
                            continue;
                        }

                        $id = (string) ($item['message']['id'] ?? '');
                        if ($id !== '') {
                            $updatedIds[$id] = true;
                        }
                    }
                }

                $latestHistoryId = isset($response['historyId']) ? (string) $response['historyId'] : $latestHistoryId;
                $nextPageToken = isset($response['nextPageToken']) && (string) $response['nextPageToken'] !== ''
                    ? (string) $response['nextPageToken']
                    : null;
            } while ($nextPageToken !== null);
        } catch (ApiRequestException $e) {
            if ($e->status === 404) {
                Event::dispatch(new DeltaTokenExpired('gmail', $mailbox, $folderId));

                return new DeltaResultDto(
                    created: collect(),
                    updated: collect(),
                    deleted: collect(),
                    deltaLink: null,
                    fullSyncRequired: true,
                );
            }

            throw $e;
        }

        foreach (array_keys($deletedIds) as $deletedId) {
            $deletedId = (string) $deletedId;
            $deleted->push($deletedId);
            unset($createdIds[$deletedId], $updatedIds[$deletedId]);
        }

        $created = $this->fetchMessages($mailbox, array_map(static fn (int|string $id): string => (string) $id, array_keys($createdIds)));
        $updated = $this->fetchMessages($mailbox, array_map(static fn (int|string $id): string => (string) $id, array_keys($updatedIds)));

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

    /** @param array<int, string> $messageIds
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
                } catch (ApiRequestException $e) {
                    if ($e->status === 404) {
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
