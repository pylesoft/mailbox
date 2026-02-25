<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\MailboxException;

class GmailMessageResource implements MessageResource
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly string $mailbox,
        private readonly string $messageId,
    ) {}

    public function get(): MessageDto
    {
        $payload = $this->client->get($this->messageEndpoint(), ['format' => 'full'], $this->mailbox);

        return MessageDto::fromGmail($payload);
    }

    public function body(): BodyDto
    {
        $message = $this->get();

        return $message->body ?? new BodyDto('text', '');
    }

    public function markAsRead(): void
    {
        $this->modifyLabels(add: [], remove: ['UNREAD']);
    }

    public function markAsUnread(): void
    {
        $this->modifyLabels(add: ['UNREAD'], remove: []);
    }

    public function moveTo(string|WellKnownFolder $folder): MessageDto
    {
        $destination = GmailLabelResolver::resolve($folder);
        $this->modifyLabels(add: [$destination], remove: $this->resolveMoveRemovals($destination));

        return $this->get();
    }

    public function copyTo(string|WellKnownFolder $folder): MessageDto
    {
        $destination = GmailLabelResolver::resolve($folder);
        $rawPayload = $this->client->get($this->messageEndpoint(), ['format' => 'raw'], $this->mailbox);
        $raw = (string) ($rawPayload['raw'] ?? '');

        if ($raw === '') {
            throw new MailboxException(
                'Unable to copy Gmail message because raw MIME payload was not returned by the provider.',
            );
        }

        $imported = $this->client->post(
            sprintf('users/%s/messages/import', rawurlencode($this->mailbox)),
            [
                'raw' => $raw,
                'labelIds' => [$destination],
            ],
            $this->mailbox,
        );

        $importedId = (string) ($imported['id'] ?? '');

        if ($importedId === '') {
            throw new MailboxException('Unable to copy Gmail message because import response did not return a message id.');
        }

        return (new self($this->client, $this->mailbox, $importedId))->get();
    }

    public function delete(): void
    {
        $this->client->delete($this->messageEndpoint(), $this->mailbox);
    }

    /** @return Collection<int, AttachmentDto> */
    public function attachments(): Collection
    {
        $message = $this->client->get($this->messageEndpoint(), ['format' => 'full'], $this->mailbox);
        $parts = $this->collectAttachmentParts((array) ($message['payload'] ?? []));

        return collect($parts)
            ->map(fn (array $part): AttachmentDto => AttachmentDto::fromGmail($part))
            ->filter(fn (AttachmentDto $attachment): bool => $attachment->id !== '')
            ->values();
    }

    public function attachment(string $attachmentId): AttachmentResource
    {
        return new GmailAttachmentResource($this->client, $this->mailbox, $this->messageId, $attachmentId);
    }

    /** @return Collection<int, AttachmentFileDto> */
    public function downloadAttachments(bool $includeInline = false): Collection
    {
        return $this->attachments()
            ->filter(fn (AttachmentDto $attachment): bool => $includeInline || ! $attachment->isInline)
            ->map(fn (AttachmentDto $attachment): AttachmentFileDto => $this->attachment($attachment->id)->download())
            ->values();
    }

    /** @param array<string> $add
     * @param  array<string>  $remove
     */
    private function modifyLabels(array $add, array $remove): void
    {
        $this->client->post($this->messageEndpoint().'/modify', [
            'addLabelIds' => array_values(array_unique($add)),
            'removeLabelIds' => array_values(array_unique($remove)),
        ], $this->mailbox);
    }

    private function messageEndpoint(): string
    {
        return sprintf('users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($this->messageId));
    }

    /** @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function collectAttachmentParts(array $payload): array
    {
        $parts = [];

        $filename = trim((string) ($payload['filename'] ?? ''));
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

        if ($filename !== '' || isset($body['attachmentId'])) {
            $parts[] = $payload;
        }

        foreach ((array) ($payload['parts'] ?? []) as $part) {
            if (! is_array($part)) {
                continue;
            }

            $parts = array_merge($parts, $this->collectAttachmentParts($part));
        }

        return $parts;
    }

    /** @return array<string> */
    private function resolveMoveRemovals(string $destination): array
    {
        return $this->defaultMoveRemovals($destination, $this->currentFolderLabel());
    }

    private function currentFolderLabel(): ?string
    {
        $payload = $this->client->get($this->messageEndpoint(), ['format' => 'minimal'], $this->mailbox);
        $labelIds = array_values(array_filter((array) ($payload['labelIds'] ?? []), 'is_string'));
        $priority = ['INBOX', 'SENT', 'DRAFT', 'TRASH', 'SPAM', 'ALL_MAIL'];

        foreach ($priority as $label) {
            if (in_array($label, $labelIds, true)) {
                return $label;
            }
        }

        return $labelIds[0] ?? null;
    }

    /** @return array<string> */
    private function defaultMoveRemovals(string $destination, ?string $sourceLabel = null): array
    {
        $candidateRemovals = ['INBOX', 'SPAM', 'TRASH', 'SENT', 'DRAFT'];

        if (is_string($sourceLabel) && trim($sourceLabel) !== '') {
            $candidateRemovals[] = trim($sourceLabel);
        }

        return array_values(array_unique(array_filter($candidateRemovals, static fn (string $label): bool => $label !== $destination)));
    }
}
