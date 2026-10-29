<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\GmailMime;
use Pyle\Mailbox\Support\ResponseBudget;

final class GmailMessageReader
{
    private BodyReadLimits $limits;

    private ResponseBudget $responses;

    private int $parts = 0;

    private int $hydrations = 0;

    private int $bodyBytes = 0;

    public function __construct(private readonly GmailClient $client, private readonly string $mailbox) {}

    /** @return array<string, mixed> */
    public function get(string $messageId, ?BodyReadLimits $limits = null, ?ResponseBudget $responses = null): array
    {
        $this->limits = $limits ?? new BodyReadLimits;
        $this->responses = $responses ?? new ResponseBudget($this->limits->maxResponseBytes);
        $this->parts = $this->hydrations = $this->bodyBytes = 0;
        $endpoint = sprintf('users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($messageId));
        $message = $this->client->getBounded($endpoint, $this->responses, ['format' => 'full'], $this->mailbox, retry: true);
        if (is_array($message['payload'] ?? null)) {
            $message['payload'] = $this->hydrate($message['payload'], $endpoint);
            $body = GmailMime::body($message['payload']);
            if ($body !== null && strlen($body->content) > $this->limits->maxBodyBytes) {
                throw new ResponseLimitExceededException('Gmail body read exceeded the combined content limit.');
            }
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>
     */
    private function hydrate(array $part, string $endpoint, bool $relatedRoot = false): array
    {
        if (++$this->parts > $this->limits->maxParts) {
            throw new ResponseLimitExceededException('Gmail body read exceeded the MIME part limit.');
        }
        if (GmailMime::isFile($part, $relatedRoot)) {
            return $part;
        }
        $body = is_array($part['body'] ?? null) ? $part['body'] : [];
        if (GmailMime::isBody($part, $relatedRoot) && isset($body['attachmentId'])) {
            if (! is_string($body['attachmentId']) || $body['attachmentId'] === '' || (array_key_exists('data', $body) && $body['data'] !== '')) {
                throw new MailboxException('Gmail returned ambiguous or malformed external body content.');
            }
            if (++$this->hydrations > $this->limits->maxHydrations) {
                throw new ResponseLimitExceededException('Gmail body read exceeded the hydration limit.');
            }
            try {
                $external = $this->client->getBounded($endpoint.'/attachments/'.rawurlencode($body['attachmentId']), $this->responses, mailbox: $this->mailbox, retry: true);
            } catch (ResourceNotFoundException) {
                throw new MailboxException('External Gmail body content was not available.');
            }
            if (! is_string($external['data'] ?? null)
                || (isset($external['attachmentId']) && $external['attachmentId'] !== $body['attachmentId'])
                || (isset($body['size'], $external['size']) && $body['size'] !== $external['size'])) {
                throw new MailboxException('Gmail returned missing or inconsistent external body content.');
            }
            $part['body'] = array_merge($external, $body, ['data' => $external['data']]);
        }
        if (GmailMime::isBody($part, $relatedRoot)) {
            $encoded = $part['body']['data'] ?? '';
            $remaining = $this->limits->maxBodyBytes - $this->bodyBytes;
            if (is_string($encoded) && strlen($encoded) > 4 * (int) ceil($remaining / 3)) {
                throw new ResponseLimitExceededException('Gmail body read exceeded the decoded content limit.');
            }
            $content = BodyDto::fromGmail($part)->content;
            $wireBytes = is_string($encoded) ? intdiv(strlen(rtrim($encoded, '=')) * 3, 4) : 0;
            $this->bodyBytes += max($wireBytes, strlen($content));
            if ($this->bodyBytes > $this->limits->maxBodyBytes) {
                throw new ResponseLimitExceededException('Gmail body read exceeded the decoded content limit.');
            }
        }
        foreach (GmailMime::children($part) as $index => $child) {
            $part['parts'][$index] = $this->hydrate($child, $endpoint, GmailMime::isRelatedRoot($part, $child, $index));
        }

        return $part;
    }
}
