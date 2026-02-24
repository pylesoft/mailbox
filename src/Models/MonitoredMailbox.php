<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $mailbox_connection_id
 * @property string $email_address
 * @property string|null $display_name
 * @property bool $is_active
 * @property \Carbon\CarbonImmutable|null $last_synced_at
 * @property MailboxConnection $connection
 * @property \Illuminate\Database\Eloquent\Collection<int, MonitoredFolder> $folders
 */
class MonitoredMailbox extends Model
{
    use SoftDeletes;

    protected $table = 'monitored_mailboxes';

    protected $fillable = [
        'mailbox_connection_id',
        'email_address',
        'display_name',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<MailboxConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(MailboxConnection::class, 'mailbox_connection_id');
    }

    /** @return HasMany<MonitoredFolder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(MonitoredFolder::class, 'monitored_mailbox_id');
    }

    /** @return HasMany<MonitoredFolder, $this> */
    public function activeFolders(): HasMany
    {
        return $this->folders()->where('is_active', true);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email_address', $email);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeStale(Builder $query, int $minutes = 30): Builder
    {
        return $query->where(fn (Builder $sub): Builder => $sub
            ->whereNull('last_synced_at')
            ->orWhere('last_synced_at', '<', Carbon::now()->subMinutes($minutes)));
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeNeverSynced(Builder $query): Builder
    {
        return $query->whereNull('last_synced_at');
    }
}
