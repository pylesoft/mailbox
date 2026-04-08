<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MailboxResourceResolver;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;
use RuntimeException;

/**
 * @property-read Mailbox|null $mailbox
 * @property-read MailboxConnection|null $mailboxConnection
 */
trait HasMailbox
{
    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return HasOneThrough<MailboxConnection, Mailbox, $this> */
    public function mailboxConnection(): HasOneThrough
    {
        return $this->hasOneThrough(
            MailboxConnection::class,
            Mailbox::class,
            'id',
            'id',
            'mailbox_id',
            'mailbox_connection_id',
        );
    }

    public function mailboxResource(): MailboxResource
    {
        $mailbox = $this->mailbox;

        if (! $mailbox instanceof Mailbox) {
            throw new RuntimeException('No mailbox is associated with this model.');
        }

        /** @var MailboxResourceResolver $resolver */
        $resolver = app(MailboxResourceResolver::class);

        return $resolver->forMailbox($mailbox);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForMailbox(Builder $query, Mailbox $mailbox): Builder
    {
        return $query->where('mailbox_id', $mailbox->id);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForConnection(Builder $query, MailboxConnection $connection): Builder
    {
        return $query->whereHas('mailbox', fn (Builder $q): Builder => $q->where('mailbox_connection_id', $connection->id));
    }
}
