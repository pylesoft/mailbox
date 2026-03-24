<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\Events\DeltaSyncCompleted;
use Pyle\Mailbox\Events\DeltaSyncStarted;
use Pyle\Mailbox\Events\DeltaTokenExpired;
use Pyle\Mailbox\Exceptions\ApiRequestException;

class MsGraphDeltaSync
{
    private readonly MsGraphDeltaCollector $collector;

    public function __construct(GraphClient $client)
    {
        $this->collector = new MsGraphDeltaCollector($client);
    }

    public function syncFolder(string $mailbox, string $folderId, ?string $deltaToken = null): DeltaResultDto
    {
        $startedAt = microtime(true);

        Event::dispatch(new DeltaSyncStarted('ms-graph', $mailbox, $folderId));
        $this->logDebug('MS Graph delta sync started', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'has_delta_token' => $deltaToken !== null,
        ]);

        try {
            $result = $this->collector->collect($mailbox, $folderId, $deltaToken);
        } catch (ApiRequestException $e) {
            if ($e->status === 410) {
                return $this->expiredDeltaResult($mailbox, $folderId);
            }

            throw $e;
        }

        return $this->completeDeltaSync(
            mailbox: $mailbox,
            folderId: $folderId,
            result: $result,
            startedAt: $startedAt,
        );
    }

    private function expiredDeltaResult(string $mailbox, string $folderId): DeltaResultDto
    {
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

    private function completeDeltaSync(
        string $mailbox,
        string $folderId,
        DeltaResultDto $result,
        float $startedAt,
    ): DeltaResultDto {
        Event::dispatch(new DeltaSyncCompleted(
            driver: 'ms-graph',
            mailbox: $mailbox,
            folder: $folderId,
            created: $result->created->count(),
            updated: $result->updated->count(),
            deleted: $result->deleted->count(),
        ));

        $this->logInfo('MS Graph delta sync completed', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'created' => $result->created->count(),
            'updated' => $result->updated->count(),
            'deleted' => $result->deleted->count(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
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
