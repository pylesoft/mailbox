<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $monitored_mailbox_id
 * @property string $provider_message_id
 * @property string $canonical_message_key
 * @property string|null $internet_message_id
 * @property string|null $parent_folder_id
 * @property string|null $subject
 * @property array<string, mixed>|null $body
 * @property string|null $body_preview
 * @property array<string, mixed>|null $from_address
 * @property array<string, mixed>|null $sender
 * @property array<int, array<string, mixed>>|null $to_recipients
 * @property array<int, array<string, mixed>>|null $cc_recipients
 * @property array<int, array<string, mixed>>|null $bcc_recipients
 * @property \Carbon\CarbonImmutable|null $received_at
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property bool $is_read
 * @property bool $is_draft
 * @property bool $has_attachments
 * @property string $importance
 * @property string|null $conversation_id
 * @property array<string, mixed>|null $raw_payload
 */
class MailboxMessage extends Model
{
    protected $table = 'mailbox_messages';

    protected $fillable = [
        'monitored_mailbox_id',
        'provider_message_id',
        'canonical_message_key',
        'internet_message_id',
        'parent_folder_id',
        'subject',
        'body',
        'body_preview',
        'from_address',
        'sender',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'received_at',
        'sent_at',
        'is_read',
        'is_draft',
        'has_attachments',
        'importance',
        'conversation_id',
        'raw_payload',
    ];

    protected $casts = [
        'body' => 'array',
        'from_address' => 'array',
        'sender' => 'array',
        'to_recipients' => 'array',
        'cc_recipients' => 'array',
        'bcc_recipients' => 'array',
        'received_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
        'is_read' => 'boolean',
        'is_draft' => 'boolean',
        'has_attachments' => 'boolean',
        'raw_payload' => 'array',
    ];

    /** @return BelongsTo<MonitoredMailbox, $this> */
    public function monitoredMailbox(): BelongsTo
    {
        return $this->belongsTo(MonitoredMailbox::class, 'monitored_mailbox_id');
    }

    /** @return HasMany<MailboxAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MailboxAttachment::class, 'mailbox_message_id');
    }
}
