<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\MessageDto;

final class MsGraphDeltaCollector
{
    public function __construct(
        private readonly GraphClient $client,
    ) {}

    public function collect(string $mailbox, string $folderId, ?string $deltaToken): DeltaResultDto
    {
        $created = collect();
        $updated = collect();
        $deleted = collect();
        $deltaLink = null;
        $endpoint = $this->initialEndpoint($mailbox, $folderId, $deltaToken);

        do {
            $response = $this->client->get($endpoint, mailbox: $mailbox);
            $this->collectResponseItems($response, $mailbox, $folderId, $created, $updated, $deleted);

            $deltaLink = isset($response['@odata.deltaLink']) ? (string) $response['@odata.deltaLink'] : $deltaLink;
            $endpoint = isset($response['@odata.nextLink']) ? (string) $response['@odata.nextLink'] : '';
        } while ($endpoint !== '');

        return new DeltaResultDto(
            created: $created,
            updated: $updated,
            deleted: $deleted,
            deltaLink: $deltaLink,
            fullSyncRequired: false,
        );
    }

    private function initialEndpoint(string $mailbox, string $folderId, ?string $deltaToken): string
    {
        return $deltaToken
            ? $deltaToken
            : sprintf('users/%s/mailFolders/%s/messages/delta', rawurlencode($mailbox), rawurlencode($folderId));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  Collection<int, MessageDto>  $created
     * @param  Collection<int, MessageDto>  $updated
     * @param  Collection<int, string>  $deleted
     */
    private function collectResponseItems(
        array $response,
        string $mailbox,
        string $folderId,
        Collection $created,
        Collection $updated,
        Collection $deleted,
    ): void {
        foreach ((array) ($response['value'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item['@removed'])) {
                $this->collectDeletedItem($item, $mailbox, $folderId, $deleted);

                continue;
            }

            $this->collectChangedItem($item, $mailbox, $folderId, $created, $updated);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, string>  $deleted
     */
    private function collectDeletedItem(array $item, string $mailbox, string $folderId, Collection $deleted): void
    {
        $deletedId = (string) ($item['id'] ?? '');
        $deleted->push($deletedId);

        $this->logDebug('MS Graph delta change processed', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'change_type' => 'deleted',
            'message_id' => $deletedId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, MessageDto>  $created
     * @param  Collection<int, MessageDto>  $updated
     */
    private function collectChangedItem(
        array $item,
        string $mailbox,
        string $folderId,
        Collection $created,
        Collection $updated,
    ): void {
        $message = MessageDto::fromMsGraph($item);
        $changeType = $this->changeType($item);

        if ($changeType === 'updated') {
            $updated->push($message);
        } else {
            $created->push($message);
        }

        $this->logDebug('MS Graph delta change processed', [
            'mailbox' => $mailbox,
            'folder' => $folderId,
            'change_type' => $changeType,
            'message_id' => $message->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function changeType(array $item): string
    {
        return isset($item['@odata.etag']) || isset($item['lastModifiedDateTime'])
            ? 'updated'
            : 'created';
    }

    /** @param array<string, mixed> $context */
    private function logDebug(string $message, array $context = []): void
    {
        $channel = (string) config('mailbox.log_channel', 'stack');

        Log::channel($channel)->debug($message, $context);
    }
}
