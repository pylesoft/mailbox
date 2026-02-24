<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MonitoredMailbox;
use RuntimeException;

/**
 * @property-read MonitoredMailbox|null $monitoredMailbox
 * @property-read MailboxConnection|null $mailboxConnection
 */
trait HasMailbox
{
    /** @return BelongsTo<MonitoredMailbox, self> */
    public function monitoredMailbox(): BelongsTo
    {
        return $this->belongsTo(MonitoredMailbox::class);
    }

    /** @return HasOneThrough<MailboxConnection, MonitoredMailbox, self> */
    public function mailboxConnection(): HasOneThrough
    {
        return $this->hasOneThrough(
            MailboxConnection::class,
            MonitoredMailbox::class,
            'id',
            'id',
            'monitored_mailbox_id',
            'mailbox_connection_id',
        );
    }

    public function mailboxResource(): MailboxResource
    {
        $mailbox = $this->monitoredMailbox;

        if (! $mailbox instanceof MonitoredMailbox) {
            throw new RuntimeException('No monitored mailbox is associated with this model.');
        }

        return Mailbox::forMailbox($mailbox);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForMailbox(Builder $query, MonitoredMailbox $mailbox): Builder
    {
        return $query->where('monitored_mailbox_id', $mailbox->id);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForConnection(Builder $query, MailboxConnection $connection): Builder
    {
        return $query->whereHas('monitoredMailbox', fn (Builder $q): Builder => $q->where('mailbox_connection_id', $connection->id));
    }
}
