<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Exceptions\ApiRequestException;

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
                throw new ApiRequestException('Gmail listing cannot complete because of a page limit or repeated page token. Use bounded initial synchronization to retain a continuation.');
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

        $payload = (new GmailMessageReader($this->client, $this->mailbox))->get($id);

        return MessageDto::fromGmail($payload);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function nextPageToken(array $response): ?string
    {
        if (! array_key_exists('nextPageToken', $response)) {
            return null;
        }

        $token = $response['nextPageToken'];
        if (! is_string($token) || trim($token) === '') {
            throw new ApiRequestException('Gmail returned an unusable listing continuation.');
        }

        return $token;
    }
}
