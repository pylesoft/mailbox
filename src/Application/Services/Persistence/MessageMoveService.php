<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MailboxMessage;

class MessageMoveService
{
    public function move(MailboxMessage $message, string|WellKnownFolder $destinationFolder): MailboxMessage
    {
        $message->loadMissing('mailbox.connection');

        $mailbox = $message->mailbox;
        if ($mailbox === null) {
            return $message;
        }

        $moved = Mailbox::forMailbox($mailbox)
            ->message($message->provider_message_id)
            ->moveTo($destinationFolder);

        $newProviderMessageId = trim($moved->id);

        if ($newProviderMessageId !== '' && $newProviderMessageId !== $message->provider_message_id) {
            $message->provider_message_id = $newProviderMessageId;

            if (blank($message->internet_message_id)) {
                $message->canonical_message_key = 'provider:'.$newProviderMessageId;
            }
        }

        $message->parent_folder_id = $moved->parentFolderId;
        $message->save();

        return $message->fresh() ?? $message;
    }
}
