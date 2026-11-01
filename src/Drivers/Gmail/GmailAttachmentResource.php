<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\SupportsAttachmentReadLimits;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\AttachmentReadLimits;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\AttachmentStorage;
use Pyle\Mailbox\Support\ConversationPayload;
use Pyle\Mailbox\Support\GmailMime;
use Pyle\Mailbox\Support\ResponseBudget;

class GmailAttachmentResource implements AttachmentResource, SupportsAttachmentReadLimits
{
    private AttachmentReadLimits $limits;

    public function __construct(
        private readonly GmailClient $client,
        private readonly string $mailbox,
        private readonly string $messageId,
        private readonly string $attachmentId,
    ) {
        $this->limits = new AttachmentReadLimits;
    }

    public function withReadLimits(AttachmentReadLimits $limits): AttachmentResource
    {
        $resource = clone $this;
        $resource->limits = $limits;

        return $resource;
    }

    public function metadata(): AttachmentDto
    {
        $part = $this->resolveAttachmentPart();

        return AttachmentDto::fromGmail($part);
    }

    public function download(): AttachmentFileDto
    {
        $responses = new ResponseBudget($this->limits->maxResponseBytes, $this->limits->maxMemoryBytes);
        $part = $this->resolveAttachmentPart($responses);

        return AttachmentStorage::store($this->resolveContent($part, $responses), AttachmentDto::fromGmail($part), $responses, 'gmail', $this->mailbox, $this->messageId, $this->attachmentId);
    }

    public function stream(): StreamInterface
    {
        $responses = new ResponseBudget($this->limits->maxResponseBytes, $this->limits->maxMemoryBytes);

        return $this->resolveContent($this->resolveAttachmentPart($responses), $responses);
    }

    /** @return array<string, mixed> */
    private function resolveAttachmentPart(?ResponseBudget $responses = null): array
    {
        $responses ??= new ResponseBudget($this->limits->maxResponseBytes, $this->limits->maxMemoryBytes);
        $message = ConversationPayload::array($this->client->getBoundedObject(
            sprintf('users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($this->messageId)),
            $responses,
            ['format' => 'full'],
            $this->mailbox,
        ));

        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        $part = $this->findPart($payload, $responses);

        if (! is_array($part)) {
            throw new ResourceNotFoundException(
                resourceType: 'attachment',
                resourceId: $this->attachmentId,
                message: sprintf('Attachment %s was not found on message %s.', $this->attachmentId, $this->messageId),
            );
        }

        return $part;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function findPart(#[\SensitiveParameter] array $payload, ResponseBudget $responses, bool $relatedRoot = false): ?array
    {
        $this->reserveMetadataMemory($payload, $responses);
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

        if (GmailMime::isFile($payload, $relatedRoot) && (($body['attachmentId'] ?? null) === $this->attachmentId || ($payload['partId'] ?? null) === $this->attachmentId)) {
            return $payload;
        }

        foreach (GmailMime::children($payload) as $index => $part) {
            $found = $this->findPart($part, $responses, GmailMime::isRelatedRoot($payload, $part, $index));

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $part */
    private function reserveMetadataMemory(#[\SensitiveParameter] array $part, ResponseBudget $responses): void
    {
        $reservation = 65536;
        foreach (['filename', 'name', 'mimeType'] as $field) {
            if (array_key_exists($field, $part) && ! is_string($part[$field])) {
                throw new MailboxException('Malformed Gmail attachment metadata.');
            }
            $bytes = strlen($part[$field] ?? '');
            if ($bytes > intdiv(PHP_INT_MAX - $reservation, 16)) {
                throw new ResponseLimitExceededException('The attachment metadata allocation estimate exceeded its limit.');
            }
            $reservation += $bytes * 16;
        }
        foreach (GmailMime::headers($part) as $header) {
            $factor = strcasecmp($header['name'], 'Content-Type') === 0 || strcasecmp($header['name'], 'Content-Disposition') === 0 ? 2048 : 16;
            foreach ([$header['name'], $header['value']] as $value) {
                $bytes = strlen($value);
                if ($bytes > intdiv(PHP_INT_MAX - $reservation, $factor)) {
                    throw new ResponseLimitExceededException('The attachment metadata allocation estimate exceeded its limit.');
                }
                $reservation += $bytes * $factor;
            }
        }
        $responses->assertMemoryAvailable($reservation);
    }

    /** @param array<string, mixed> $part */
    private function resolveContent(#[\SensitiveParameter] array $part, ResponseBudget $responses): StreamInterface
    {
        $body = is_array($part['body'] ?? null) ? $part['body'] : [];
        $this->declaredSize($body);
        if ((array_key_exists('data', $body) && ! is_string($body['data']))
            || (array_key_exists('attachmentId', $body) && ! is_string($body['attachmentId']))) {
            throw new MailboxException('Malformed Gmail attachment content.');
        }
        $inlineData = $body['data'] ?? '';
        $attachmentId = $body['attachmentId'] ?? '';
        if ($inlineData !== '' && $attachmentId !== '') {
            throw new MailboxException('Gmail attachment content is ambiguous.');
        }

        if ($inlineData !== '') {
            return $this->decodeBase64Url($inlineData, $responses, $body);
        }

        if ($attachmentId === '') {
            if (! array_key_exists('data', $body) && ($body['size'] ?? null) !== 0) {
                throw new MailboxException('Gmail attachment content is missing.');
            }

            return $this->decodeBase64Url('', $responses, $body);
        }

        $payload = $this->client->getBounded(
            sprintf(
                'users/%s/messages/%s/attachments/%s',
                rawurlencode($this->mailbox),
                rawurlencode($this->messageId),
                rawurlencode($attachmentId),
            ),
            $responses,
            mailbox: $this->mailbox,
        );

        if ((! array_key_exists('data', $payload) && ($payload['size'] ?? null) !== 0)
            || (array_key_exists('data', $payload) && ! is_string($payload['data']))
            || (array_key_exists('attachmentId', $payload) && $payload['attachmentId'] !== $attachmentId)) {
            throw new MailboxException('Gmail attachment content is missing or malformed.');
        }

        return $this->decodeBase64Url((string) ($payload['data'] ?? ''), $responses, $body, $payload);
    }

    /** @param array<string, mixed> $body */
    private function validateSize(array $body, int $size): void
    {
        $declared = $this->declaredSize($body);
        if ($declared !== null && $declared !== $size) {
            throw new MailboxException('Gmail attachment size does not match its content.');
        }
    }

    /** @param array<string, mixed> $body */
    private function declaredSize(array $body): ?int
    {
        if (! array_key_exists('size', $body)) {
            return null;
        }
        if (! is_int($body['size']) || $body['size'] < 0) {
            throw new MailboxException('Malformed Gmail attachment size.');
        }
        if ($body['size'] > $this->limits->maxFileBytes) {
            throw new ResponseLimitExceededException('The declared attachment exceeds its file byte limit.');
        }

        return $body['size'];
    }

    /** @param array<string, mixed> ...$declarations */
    private function decodeBase64Url(#[\SensitiveParameter] string $encoded, ResponseBudget $responses, #[\SensitiveParameter] array ...$declarations): StreamInterface
    {
        foreach ($declarations as $declaration) {
            $this->declaredSize($declaration);
        }
        $responses->assertMemoryAvailable(65536);
        $temporary = @tmpfile();
        if ($temporary === false) {
            throw new MailboxException('An owned temporary attachment stream could not be created.');
        }
        $size = 0;
        try {
            $length = strlen($encoded);
            for ($offset = 0; $offset < $length; $offset += 8192) {
                $responses->assertMemoryAvailable(65536);
                $chunk = substr($encoded, $offset, 8192);
                if (preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $chunk) !== 1
                    || (str_contains($chunk, '=') && (strlen($chunk) % 4 !== 0 || $length - $offset > 8192))) {
                    throw new MailboxException('Malformed Gmail attachment base64url.');
                }
                $decoded = base64_decode(strtr($chunk, '-_', '+/'), true);
                if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== rtrim($chunk, '=')) {
                    throw new MailboxException('Malformed or truncated Gmail attachment base64url.');
                }
                if (strlen($decoded) > $this->limits->maxFileBytes - $size) {
                    throw new ResponseLimitExceededException('The decoded attachment exceeds its file byte limit.');
                }
                $size += strlen($decoded);
                if (@fwrite($temporary, $decoded) !== strlen($decoded)) {
                    throw new MailboxException('The owned temporary attachment stream could not be written completely.');
                }
            }
            foreach ($declarations as $declaration) {
                $this->validateSize($declaration, $size);
            }
            rewind($temporary);

            return Utils::streamFor($temporary);
        } catch (\Throwable $exception) {
            fclose($temporary);
            throw $exception;
        }
    }
}
