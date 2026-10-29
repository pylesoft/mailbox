<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $mailbox_connection_id
 * @property string|null $grant_id
 * @property string|null $lifecycle_generation
 * @property string|null $issuer
 * @property string|null $client_id
 * @property string|null $grant_revision
 * @property string|null $nonce_hash
 * @property list<string>|null $grant_revision_history
 * @property string $provider
 * @property string|null $external_user_id
 * @property string|null $email
 * @property string|null $tenant_id
 * @property string $access_token
 * @property string|null $refresh_token
 * @property string $token_type
 * @property array<int, string>|null $scopes
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_refreshed_at
 * @property CarbonImmutable|null $revoked_at
 * @property array<string, mixed>|null $meta
 * @property MailboxConnection|null $connection
 */
class MailboxOAuthToken extends Model
{
    protected $table = 'mailbox_oauth_tokens';

    protected $fillable = [
        'mailbox_connection_id',
        'grant_id',
        'lifecycle_generation',
        'issuer',
        'client_id',
        'grant_revision',
        'nonce_hash',
        'grant_revision_history',
        'provider',
        'external_user_id',
        'email',
        'tenant_id',
        'access_token',
        'refresh_token',
        'token_type',
        'scopes',
        'expires_at',
        'last_refreshed_at',
        'revoked_at',
        'meta',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'nonce_hash' => 'encrypted',
        'grant_revision_history' => 'encrypted:array',
        'scopes' => 'array',
        'meta' => 'array',
        'expires_at' => 'immutable_datetime',
        'last_refreshed_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'nonce_hash', 'grant_revision_history'];

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->attributesToArray();
    }

    /** @return BelongsTo<MailboxConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(MailboxConnection::class, 'mailbox_connection_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiringSoon(Builder $query, int $seconds = 300): Builder
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addSeconds($seconds));
    }
}
