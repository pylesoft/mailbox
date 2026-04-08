<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Models\MailboxMessage;

final class MailboxMessagePersister
{
    /**
     * @param  Collection<int, AttachmentDto>|null  $prefetchedAttachments
     */
    public function upsert(
        MailboxResource $mailboxResource,
        int $mailboxId,
        MessageDto $message,
        ?MessageResource $resource = null,
        ?Collection $prefetchedAttachments = null,
        bool $persistAttachments = true,
    ): MailboxMessage {
        $mailboxMessage = MailboxMessage::query()->updateOrCreate(
            [
                'mailbox_id' => $mailboxId,
                'canonical_message_key' => $this->canonicalMessageKey($message),
            ],
            [
                'provider_message_id' => $message->id,
                'internet_message_id' => $message->internetMessageId,
                'parent_folder_id' => $message->parentFolderId,
                'subject' => $message->subject,
                'body' => $message->body?->toArray(),
                'body_preview' => $message->bodyPreview,
                'from_address' => $this->normalizeAddress($message->from),
                'sender' => $this->normalizeAddress($message->sender),
                'to_recipients' => $this->normalizeAddressList($message->toRecipients),
                'cc_recipients' => $this->normalizeAddressList($message->ccRecipients),
                'bcc_recipients' => $this->normalizeAddressList($message->bccRecipients),
                'received_at' => $message->receivedAt,
                'sent_at' => $message->sentAt,
                'is_read' => $message->isRead,
                'is_draft' => $message->isDraft,
                'has_attachments' => $message->hasAttachments,
                'importance' => $message->importance->value,
                'conversation_id' => $message->conversationId,
                'raw_payload' => $message->raw,
            ],
        );

        if (! $persistAttachments) {
            return $mailboxMessage->fresh(['attachments']) ?? $mailboxMessage;
        }

        if (! $message->hasAttachments) {
            $mailboxMessage->attachments()->delete();

            return $mailboxMessage->fresh(['attachments']) ?? $mailboxMessage;
        }

        $resource ??= $mailboxResource->message($message->id);
        $attachments = $prefetchedAttachments ?? $resource->attachments();
        $persistedAttachmentIds = [];

        foreach ($attachments as $attachment) {
            if ($attachment->id === '') {
                continue;
            }

            $persistedAttachmentIds[] = $attachment->id;
            $content = (string) $resource->attachment($attachment->id)->stream();

            $mailboxMessage->attachments()->updateOrCreate(
                [
                    'mailbox_message_id' => $mailboxMessage->id,
                    'provider_attachment_id' => $attachment->id,
                ],
                [
                    'name' => $attachment->name,
                    'content_type' => $attachment->contentType,
                    'size' => $attachment->size,
                    'is_inline' => $attachment->isInline,
                    'content_id' => $attachment->contentId,
                    'content_bytes' => base64_encode($content),
                ],
            );
        }

        if ($persistedAttachmentIds === []) {
            $mailboxMessage->attachments()->delete();
        } else {
            $mailboxMessage->attachments()
                ->whereNotIn('provider_attachment_id', array_values(array_unique($persistedAttachmentIds)))
                ->delete();
        }

        return $mailboxMessage->fresh(['attachments']) ?? $mailboxMessage;
    }

    private function canonicalMessageKey(MessageDto $message): string
    {
        $internetMessageId = trim((string) ($message->internetMessageId ?? ''));

        if ($internetMessageId !== '') {
            return "internet:{$internetMessageId}";
        }

        return "provider:{$message->id}";
    }

    /**
     * @return array{name: string, address: string}|null
     */
    private function normalizeAddress(?EmailAddressDto $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return ['name' => $address->name, 'address' => $address->address];
    }

    /**
     * @param  array<int, EmailAddressDto>  $addresses
     * @return array<int, array{name: string, address: string}>
     */
    private function normalizeAddressList(array $addresses): array
    {
        return array_values(array_map(fn (EmailAddressDto $address): array => [
            'name' => $address->name,
            'address' => $address->address,
        ], $addresses));
    }
}
