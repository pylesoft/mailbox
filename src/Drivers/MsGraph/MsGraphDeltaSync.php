<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Events\DeltaSyncCompleted;
use Pyle\Mailbox\Events\DeltaSyncStarted;
use Pyle\Mailbox\Events\DeltaTokenExpired;
use Pyle\Mailbox\Exceptions\ApiRequestException;

class MsGraphDeltaSync
{
    public function __construct(
        private readonly GraphClient $client,
    ) {}

    public function syncFolder(string $mailbox, string $folderId, ?string $deltaToken = null): DeltaResultDto
    {
        $startedAt = microtime(true);

        Event::dispatch(new DeltaSyncStarted('ms-graph', $mailbox, $folderId));
        $this->logDebug('MS Graph delta sync started', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'has_delta_token' => $deltaToken !== null,
        ]);

        $created = collect();
        $updated = collect();
        $deleted = collect();
        $deltaLink = null;

        $endpoint = $deltaToken
            ? $deltaToken
            : sprintf('users/%s/mailFolders/%s/messages/delta', rawurlencode($mailbox), rawurlencode($folderId));

        try {
            do {
                $response = $this->client->get($endpoint, mailbox: $mailbox);
                $items = (array) ($response['value'] ?? []);

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    if (isset($item['@removed'])) {
                        $deletedId = (string) ($item['id'] ?? '');
                        $deleted->push($deletedId);

                        $this->logDebug('MS Graph delta change processed', [
                            'mailbox' => $mailbox,
                            'folder' => $folderId,
                            'change_type' => 'deleted',
                            'message_id' => $deletedId,
                        ]);

                        continue;
                    }

                    $message = MessageDto::fromMsGraph($item);

                    if (isset($item['@odata.etag']) || isset($item['lastModifiedDateTime'])) {
                        $updated->push($message);
                        $this->logDebug('MS Graph delta change processed', [
                            'mailbox' => $mailbox,
                            'folder' => $folderId,
                            'change_type' => 'updated',
                            'message_id' => $message->id,
                        ]);
                    } else {
                        $created->push($message);
                        $this->logDebug('MS Graph delta change processed', [
                            'mailbox' => $mailbox,
                            'folder' => $folderId,
                            'change_type' => 'created',
                            'message_id' => $message->id,
                        ]);
                    }
                }

                $deltaLink = isset($response['@odata.deltaLink']) ? (string) $response['@odata.deltaLink'] : $deltaLink;
                $endpoint = isset($response['@odata.nextLink']) ? (string) $response['@odata.nextLink'] : '';
            } while ($endpoint !== '');
        } catch (ApiRequestException $e) {
            if ($e->status === 410) {
                Event::dispatch(new DeltaTokenExpired('ms-graph', $mailbox, $folderId));

                $this->logInfo('MS Graph delta token expired', [
                    'mailbox' => $mailbox,
                    'folder' => $folderId,
                ]);

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

        Event::dispatch(new DeltaSyncCompleted(
            driver: 'ms-graph',
            mailbox: $mailbox,
            folder: $folderId,
            created: $created->count(),
            updated: $updated->count(),
            deleted: $deleted->count(),
        ));

        $this->logInfo('MS Graph delta sync completed', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'created' => $created->count(),
            'updated' => $updated->count(),
            'deleted' => $deleted->count(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return new DeltaResultDto(
            created: new Collection($created->all()),
            updated: new Collection($updated->all()),
            deleted: new Collection($deleted->all()),
            deltaLink: $deltaLink,
            fullSyncRequired: false,
        );
    }

    /** @param array<string, mixed> $context */
    private function logDebug(string $message, array $context = []): void
    {
        Log::channel((string) config('mailbox.log_channel', 'stack'))->debug($message, $context);
    }

    /** @param array<string, mixed> $context */
    private function logInfo(string $message, array $context = []): void
    {
        Log::channel((string) config('mailbox.log_channel', 'stack'))->info($message, $context);
    }
}
