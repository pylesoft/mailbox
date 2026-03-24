<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Drivers\Gmail\GmailMessageFilterer;

final class GmailMessagePageCollector
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly string $mailbox,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, MessageDto>
     */
    public function collect(
        array $query,
        int $maxPages,
        ?int $limit = null,
        ?GmailMessageFilterer $filterer = null,
    ): Collection {
        $endpoint = sprintf('users/%s/messages', rawurlencode($this->mailbox));
        $collected = collect();
        $nextPageToken = null;
        $seenPageTokens = [];
        $seenMessageIds = [];
        $pageCount = 0;

        do {
            $tokenSignature = $nextPageToken ?? '__first__';

            if ($this->shouldStopPaging($tokenSignature, $seenPageTokens, $pageCount, $maxPages)) {
                break;
            }

            $seenPageTokens[$tokenSignature] = true;
            $pageCount++;

            $response = $this->client->get($endpoint, $this->queryForPage($query, $nextPageToken), $this->mailbox);
            $messages = $this->hydrateMessages((array) ($response['messages'] ?? []), $seenMessageIds);

            if ($filterer !== null) {
                $messages = $filterer->apply($messages);
            }

            $collected = $collected->concat($messages);

            if ($limit !== null && $collected->count() >= $limit) {
                return $collected->take($limit)->values();
            }

            $nextPageToken = $this->nextPageToken($response);
        } while ($nextPageToken !== null);

        return $collected->values();
    }

    /**
     * @param  array<string, bool>  $seenPageTokens
     */
    private function shouldStopPaging(string $tokenSignature, array $seenPageTokens, int $pageCount, int $maxPages): bool
    {
        return isset($seenPageTokens[$tokenSignature]) || $pageCount >= $maxPages;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function queryForPage(array $query, ?string $nextPageToken): array
    {
        if ($nextPageToken === null) {
            unset($query['pageToken']);

            return $query;
        }

        $query['pageToken'] = $nextPageToken;

        return $query;
    }

    /**
     * @param  array<int, mixed>  $summaries
     * @param  array<string, bool>  $seenMessageIds
     * @return Collection<int, MessageDto>
     */
    private function hydrateMessages(array $summaries, array &$seenMessageIds): Collection
    {
        return collect($summaries)
            ->map(function (mixed $summary) use (&$seenMessageIds): ?MessageDto {
                return $this->hydrateSummary($summary, $seenMessageIds);
            })
            ->filter(fn (?MessageDto $message): bool => $message instanceof MessageDto)
            ->values();
    }

    /**
     * @param  array<string, bool>  $seenMessageIds
     */
    private function hydrateSummary(mixed $summary, array &$seenMessageIds): ?MessageDto
    {
        if (! is_array($summary)) {
            return null;
        }

        $id = (string) ($summary['id'] ?? '');

        if ($id === '' || isset($seenMessageIds[$id])) {
            return null;
        }

        $seenMessageIds[$id] = true;

        $payload = $this->client->get(
            sprintf('users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($id)),
            ['format' => 'full'],
            $this->mailbox,
        );

        return MessageDto::fromGmail($payload);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function nextPageToken(array $response): ?string
    {
        return isset($response['nextPageToken']) && (string) $response['nextPageToken'] !== ''
            ? (string) $response['nextPageToken']
            : null;
    }
}
