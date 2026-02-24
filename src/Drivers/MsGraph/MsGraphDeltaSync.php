<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
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
        Event::dispatch(new DeltaSyncStarted('ms-graph', $mailbox, $folderId));

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
                        $deleted->push((string) ($item['id'] ?? ''));

                        continue;
                    }

                    $message = MessageDto::fromMsGraph($item);

                    if (isset($item['@odata.etag']) || isset($item['lastModifiedDateTime'])) {
                        $updated->push($message);
                    } else {
                        $created->push($message);
                    }
                }

                $deltaLink = isset($response['@odata.deltaLink']) ? (string) $response['@odata.deltaLink'] : $deltaLink;
                $endpoint = isset($response['@odata.nextLink']) ? (string) $response['@odata.nextLink'] : '';
            } while ($endpoint !== '');
        } catch (ApiRequestException $e) {
            if ($e->status === 410) {
                Event::dispatch(new DeltaTokenExpired('ms-graph', $mailbox, $folderId));

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

        return new DeltaResultDto(
            created: new Collection($created->all()),
            updated: new Collection($updated->all()),
            deleted: new Collection($deleted->all()),
            deltaLink: $deltaLink,
            fullSyncRequired: false,
        );
    }
}
