<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Traits\HasMailbox;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property string $folder_id
 * @property string $display_name
 * @property string|null $path
 * @property WellKnownFolder|null $well_known_name
 * @property bool $is_active
 * @property string|null $delta_token
 * @property \Carbon\CarbonImmutable|null $last_synced_at
 * @property SyncStatus $sync_status
 * @property string|null $last_sync_error
 * @property Mailbox|null $mailbox
 */
class Folder extends Model
{
    use HasMailbox;

    protected $table = 'mailbox_folders';

    protected $fillable = [
        'mailbox_id',
        'folder_id',
        'display_name',
        'path',
        'well_known_name',
        'is_active',
        'delta_token',
        'last_synced_at',
        'sync_status',
        'last_sync_error',
    ];

    protected $casts = [
        'well_known_name' => WellKnownFolder::class,
        'sync_status' => SyncStatus::class,
        'is_active' => 'boolean',
        'last_synced_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
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
    public function scopeSyncing(Builder $query): Builder
    {
        return $query->where('sync_status', SyncStatus::SYNCING);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeWithErrors(Builder $query): Builder
    {
        return $query->where('sync_status', SyncStatus::ERROR);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeNeedsSync(Builder $query, int $minutes = 15): Builder
    {
        return $query->where('is_active', true)->where(fn (Builder $sub): Builder => $sub
            ->whereNull('last_synced_at')
            ->orWhere('last_synced_at', '<', Carbon::now()->subMinutes($minutes)));
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForWellKnown(Builder $query, WellKnownFolder $folder): Builder
    {
        return $query->where('well_known_name', $folder);
    }
}
