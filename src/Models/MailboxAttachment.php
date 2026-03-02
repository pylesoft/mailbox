<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $mailbox_message_id
 * @property string $provider_attachment_id
 * @property string $name
 * @property string $content_type
 * @property int $size
 * @property bool $is_inline
 * @property string|null $content_id
 * @property string|null $content_bytes
 */
class MailboxAttachment extends Model
{
    protected $table = 'mailbox_attachments';

    protected $fillable = [
        'mailbox_message_id',
        'provider_attachment_id',
        'name',
        'content_type',
        'size',
        'is_inline',
        'content_id',
        'content_bytes',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_inline' => 'boolean',
    ];

    /** @return BelongsTo<MailboxMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'mailbox_message_id');
    }
}
