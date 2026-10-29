<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Pyle\Mailbox\Support\GmailMime;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\Exceptions\MailboxException;

final readonly class GmailMessageReader
{
    public function __construct(private GmailClient $client, private string $mailbox) {}

    /** @return array<string, mixed> */
    public function get(string $messageId): array
    {
        $endpoint = sprintf('users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($messageId));
        $message = $this->client->get($endpoint, ['format' => 'full'], $this->mailbox);
        if (is_array($message['payload'] ?? null)) {
            $message['payload'] = $this->hydrate($message['payload'], $endpoint);
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    private function hydrate(array $part, string $endpoint): array
    {
        if (GmailMime::isFile($part)) {
            return $part;
        }
        $body = is_array($part['body'] ?? null) ? $part['body'] : [];
        if (GmailMime::isBody($part) && isset($body['attachmentId'])) {
            if (! is_string($body['attachmentId']) || $body['attachmentId'] === '' || (array_key_exists('data', $body) && $body['data'] !== '')) {
                throw new MailboxException('Gmail returned ambiguous or malformed external body content.');
            }
            $external = $this->client->get($endpoint.'/attachments/'.rawurlencode($body['attachmentId']), mailbox: $this->mailbox);
            if (! is_string($external['data'] ?? null)
                || (isset($external['attachmentId']) && $external['attachmentId'] !== $body['attachmentId'])
                || (isset($body['size'], $external['size']) && $body['size'] !== $external['size'])) {
                throw new MailboxException('Gmail returned missing or inconsistent external body content.');
            }
            $part['body'] = array_merge($external, $body, ['data' => $external['data']]);
        }
        if (GmailMime::isBody($part)) {
            BodyDto::fromGmail($part);
        }
        foreach (GmailMime::children($part) as $index => $child) {
            $part['parts'][$index] = $this->hydrate($child, $endpoint);
        }

        return $part;
    }
}
