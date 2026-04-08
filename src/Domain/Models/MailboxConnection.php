<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxDriverResolver;
use Pyle\Mailbox\Enums\ConnectionStatus;

/**
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property ConnectionStatus $status
 * @property array<string, mixed>|null $config
 * @property \Carbon\CarbonImmutable|null $last_connected_at
 * @property string|null $last_error
 * @property \Illuminate\Database\Eloquent\Collection<int, Mailbox> $mailboxes
 * @property \Illuminate\Database\Eloquent\Collection<int, MailboxOAuthToken> $oauthTokens
 */
class MailboxConnection extends Model
{
    use SoftDeletes;

    protected $table = 'mailbox_connections';

    protected $fillable = [
        'name',
        'driver',
        'status',
        'config',
        'last_connected_at',
        'last_error',
    ];

    protected $casts = [
        'status' => ConnectionStatus::class,
        'config' => 'encrypted:array',
        'last_connected_at' => 'immutable_datetime',
    ];

    /** @return HasMany<Mailbox, $this> */
    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'mailbox_connection_id');
    }

    /** @return HasMany<Mailbox, $this> */
    public function activeMailboxes(): HasMany
    {
        return $this->mailboxes()->where('is_active', true);
    }

    /** @return HasMany<MailboxOAuthToken, $this> */
    public function oauthTokens(): HasMany
    {
        return $this->hasMany(MailboxOAuthToken::class, 'mailbox_connection_id');
    }

    public function resolveDriver(): MailboxDriver
    {
        /** @var MailboxDriverResolver $resolver */
        $resolver = app(MailboxDriverResolver::class);

        return $resolver->driver($this->driver);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ConnectionStatus::CONNECTED);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForDriver(Builder $query, string $driver): Builder
    {
        return $query->where('driver', $driver);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeWithError(Builder $query): Builder
    {
        return $query->where('status', ConnectionStatus::ERROR);
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeConnectedSince(Builder $query, Carbon $since): Builder
    {
        return $query->where('last_connected_at', '>=', $since);
    }
}
