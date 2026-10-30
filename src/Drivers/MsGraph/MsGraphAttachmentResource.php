<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\SupportsAttachmentReadLimits;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\AttachmentReadLimits;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\AttachmentStorage;
use Pyle\Mailbox\Support\ResponseBudget;

class MsGraphAttachmentResource implements AttachmentResource, SupportsAttachmentReadLimits
{
    private AttachmentReadLimits $limits;

    private const ATTACHMENT_METADATA_SELECT = 'id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId';

    public function __construct(
        private readonly GraphClient $client,
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
        return AttachmentDto::fromMsGraph($this->readMetadata(new ResponseBudget($this->limits->maxResponseBytes, $this->limits->maxMemoryBytes)));
    }

    public function download(): AttachmentFileDto
    {
        $responses = new ResponseBudget($this->limits->maxResponseBytes, $this->limits->maxMemoryBytes);
        $payload = $this->readMetadata($responses);

        return AttachmentStorage::store($this->resolveContent($payload, $responses), AttachmentDto::fromMsGraph($payload), $responses, 'ms-graph', $this->mailbox, $this->messageId, $this->attachmentId);
    }

    /** @param array<string, mixed> $payload */
    private function resolveContent(array $payload, ResponseBudget $responses): StreamInterface
    {
        $type = $payload['@odata.type'] ?? '#microsoft.graph.fileAttachment';
        if (! in_array($type, ['#microsoft.graph.fileAttachment', '#microsoft.graph.itemAttachment'], true)) {
            throw new MailboxException('This Graph attachment form does not provide supported file bytes.');
        }
        $file = $type === '#microsoft.graph.fileAttachment';
        if ($file && ($payload['size'] ?? 0) > $this->limits->maxFileBytes) {
            throw new ResponseLimitExceededException('The declared attachment exceeds its file byte limit.');
        }
        $content = $this->client->streamBounded($this->attachmentEndpoint().'/$value', $responses, $this->limits->maxFileBytes, $this->mailbox);
        if ($file && array_key_exists('size', $payload) && $content->getSize() !== $payload['size']) {
            $content->close();
            throw new MailboxException('Graph attachment size does not match its content.');
        }

        return $content;
    }

    /** @return array<string, mixed> */
    private function readMetadata(ResponseBudget $responses): array
    {
        $payload = $this->client->getBounded($this->attachmentEndpoint(), $responses, ['$select' => self::ATTACHMENT_METADATA_SELECT], $this->mailbox);
        if (($payload['id'] ?? null) !== $this->attachmentId
            || (array_key_exists('size', $payload) && (! is_int($payload['size']) || $payload['size'] < 0))) {
            throw new MailboxException('Graph returned missing or inconsistent attachment metadata.');
        }
        foreach (['name', 'contentType', '@odata.type'] as $field) {
            if (array_key_exists($field, $payload) && ! is_string($payload[$field])) {
                throw new MailboxException('Graph returned malformed attachment metadata.');
            }
        }
        if ((isset($payload['contentId']) && ! is_string($payload['contentId']))
            || (array_key_exists('isInline', $payload) && ! is_bool($payload['isInline']))) {
            throw new MailboxException('Graph returned malformed attachment metadata.');
        }

        return $payload;
    }

    public function stream(): StreamInterface
    {
        $responses = new ResponseBudget($this->limits->maxResponseBytes, $this->limits->maxMemoryBytes);

        return $this->resolveContent($this->readMetadata($responses), $responses);
    }

    private function attachmentEndpoint(): string
    {
        return sprintf(
            'users/%s/messages/%s/attachments/%s',
            rawurlencode($this->mailbox),
            rawurlencode($this->messageId),
            rawurlencode($this->attachmentId),
        );
    }
}
