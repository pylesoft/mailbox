<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\MessageQueryRejection;
use Pyle\Mailbox\DTOs\MessageQueryResult;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidReceivedHeaderException;
use Pyle\Mailbox\Support\ConversationPayload;
use Pyle\Mailbox\Support\ProviderMessageId;
use Pyle\Mailbox\Support\ResponseBudget;

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
        return $this->execute($query, $maxPages, $limit, $filterer, false)->messages;
    }

    /** @param array<string, mixed> $query */
    public function collectResult(
        array $query,
        int $maxPages,
        ?int $limit = null,
        ?GmailMessageFilterer $filterer = null,
    ): MessageQueryResult {
        return $this->execute($query, $maxPages, $limit, $filterer, true);
    }

    /** @param array<string, mixed> $query */
    private function execute(array $query, int $maxPages, ?int $limit, ?GmailMessageFilterer $filterer, bool $isolateHeaders): MessageQueryResult
    {
        $endpoint = sprintf('users/%s/messages', rawurlencode($this->mailbox));
        $collected = collect();
        $rejections = collect();
        $budget = new ResponseBudget((new BodyReadLimits)->maxResponseBytes);
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

            $pageQuery = $this->queryForPage($query, $nextPageToken);
            $response = $isolateHeaders
                ? ConversationPayload::array($this->client->getBoundedObject($endpoint, $budget, $pageQuery, $this->mailbox, retry: true))
                : $this->client->get($endpoint, $pageQuery, $this->mailbox);
            $messages = $this->hydrateMessages((array) ($response['messages'] ?? []), $seenMessageIds, $isolateHeaders, $rejections);

            if ($filterer !== null) {
                $messages = $filterer->apply($messages);
            }

            $collected = $collected->concat($messages);

            if ($limit !== null && $collected->count() >= $limit) {
                $next = $isolateHeaders ? $this->nextPageToken($response) : null;
                if ($isolateHeaders && $next !== null && isset($seenPageTokens[$next])) {
                    throw new ApiRequestException('Gmail returned a repeated listing continuation.');
                }

                return new MessageQueryResult($collected->take($limit)->values(), $rejections,
                    $collected->count() === $limit && ! array_key_exists('nextPageToken', $response));
            }

            $nextPageToken = $this->nextPageToken($response);
        } while ($nextPageToken !== null);

        return new MessageQueryResult($collected->values(), $rejections, true);
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
     * @param  Collection<int, MessageQueryRejection>  $rejections
     * @return Collection<int, MessageDto>
     */
    private function hydrateMessages(array $summaries, array &$seenMessageIds, bool $isolateHeaders, Collection $rejections): Collection
    {
        return collect($summaries)
            ->map(function (mixed $summary) use (&$seenMessageIds, $isolateHeaders, $rejections): ?MessageDto {
                return $this->hydrateSummary($summary, $seenMessageIds, $isolateHeaders, $rejections);
            })
            ->filter(fn (?MessageDto $message): bool => $message instanceof MessageDto)
            ->values();
    }

    /**
     * @param  array<string, bool>  $seenMessageIds
     * @param  Collection<int, MessageQueryRejection>  $rejections
     */
    private function hydrateSummary(mixed $summary, array &$seenMessageIds, bool $isolateHeaders, Collection $rejections): ?MessageDto
    {
        if ($isolateHeaders && (! is_array($summary) || ! ProviderMessageId::isValid($summary['id'] ?? null))) {
            throw new ApiRequestException('Gmail returned a malformed listed message identity.');
        }
        if (! is_array($summary)) {
            return null;
        }

        $id = (string) ($summary['id'] ?? '');

        if ($id === '' || isset($seenMessageIds[$id])) {
            return null;
        }

        $seenMessageIds[$id] = true;

        try {
            $reader = new GmailMessageReader($this->client, $this->mailbox);
            $payload = $isolateHeaders ? $reader->getForQueryResult($id) : $reader->get($id);

            return MessageDto::fromGmail($payload);
        } catch (InvalidReceivedHeaderException $exception) {
            if (! $isolateHeaders) {
                throw $exception;
            }
            $rejections->push(new MessageQueryRejection($id));

            return null;
        }
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
