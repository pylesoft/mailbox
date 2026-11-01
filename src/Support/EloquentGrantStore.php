<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Pyle\Mailbox\Contracts\GrantStore;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GrantIdentity;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxOAuthToken;
use Throwable;

final class EloquentGrantStore implements GrantStore
{
    public function load(GrantContext $context): ?StoredGrant
    {
        if (! $this->canonicalConnectionId($context)) {
            return null;
        }

        try {
            $record = MailboxOAuthToken::query()
                ->select(array_map(static fn (string $column): string => 'mailbox_oauth_tokens.'.$column, [
                    'id', 'mailbox_connection_id', 'grant_id', 'lifecycle_generation', 'provider', 'issuer', 'external_user_id',
                    'client_id', 'tenant_id', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'grant_revision', 'email', 'meta', 'nonce_hash',
                ]))
                ->addSelect('mailbox_connections.selected_grant_id as connection_grant', 'mailbox_connections.lifecycle_generation as connection_generation',
                    'mailbox_connections.driver as connection_driver', 'mailbox_connections.status as connection_status')
                ->join('mailbox_connections', 'mailbox_connections.id', '=', 'mailbox_oauth_tokens.mailbox_connection_id')
                ->where('mailbox_connections.id', $context->connectionId)
                ->whereNull('mailbox_connections.deleted_at')
                ->where('mailbox_oauth_tokens.grant_id', $context->grantId)
                ->where('mailbox_oauth_tokens.lifecycle_generation', $context->lifecycleGeneration)
                ->whereNull('mailbox_oauth_tokens.revoked_at')->first();

            if ($record === null || (string) $record->mailbox_connection_id !== $context->connectionId
                || $record->grant_id !== $context->grantId || $record->lifecycle_generation !== $context->lifecycleGeneration
                || $record->getAttribute('connection_grant') !== $context->grantId
                || $record->getAttribute('connection_generation') !== $context->lifecycleGeneration
                || $record->getAttribute('connection_driver') !== $context->driver
                || $record->provider !== ($context->driver === 'google-workspace' ? 'gmail' : $context->driver)
                || $record->getAttribute('connection_status') === ConnectionStatus::DISABLED->value) {
                return null;
            }

            return $this->grant($record);
        } catch (Throwable) {
            throw new GrantRefreshException('The selected grant could not be loaded safely.', true, 1);
        }
    }

    public function replace(GrantContext $context, ?StoredGrant $expected, StoredGrant $replacement): bool
    {
        $provider = $context->driver === 'google-workspace' ? 'gmail' : $context->driver;
        if (! $this->canonicalConnectionId($context) || $replacement->identity->provider !== $provider || trim($replacement->revision) === '') {
            return false;
        }

        try {
            return (new MailboxConnection)->getConnection()->transaction(function () use ($context, $expected, $replacement): bool {
                $connections = MailboxConnection::query()->whereKey($context->connectionId);
                // A write before reading also serializes SQLite, which ignores SELECT FOR UPDATE.
                $connections->toBase()->update(['id' => new Expression('id')]);
                $connection = $connections->lockForUpdate()->first();
                if ($connection === null || (string) $connection->id !== $context->connectionId
                    || $connection->selected_grant_id !== $context->grantId || $connection->lifecycle_generation !== $context->lifecycleGeneration
                    || $connection->driver !== $context->driver || $connection->status === ConnectionStatus::DISABLED) {
                    return false;
                }

                $record = $connection->oauthTokens()->where('grant_id', $context->grantId)->lockForUpdate()->first();
                if ($record !== null && ($record->grant_id !== $context->grantId || (string) $record->mailbox_connection_id !== $context->connectionId)) {
                    return false;
                }
                $current = $record !== null && $record->lifecycle_generation === $context->lifecycleGeneration && $record->revoked_at === null
                    ? $this->grant($record) : null;
                if ($expected === null ? ($record !== null && $record->lifecycle_generation === $context->lifecycleGeneration)
                    : ($current === null || $current->revision !== $expected->revision || ! $current->identity->matches($expected->identity)
                        || ! $current->identity->matches($replacement->identity)
                        || ($current->identity->provider === 'ms-graph'
                            && (($current->metadata['object_id'] ?? null) !== ($expected->metadata['object_id'] ?? null)
                                || ($current->metadata['object_id'] ?? null) !== ($replacement->metadata['object_id'] ?? null))))) {
                    return false;
                }

                $history = $record->grant_revision_history ?? [];
                if ($record?->grant_revision !== null) {
                    $history[] = hash('sha256', $record->grant_revision);
                }
                $revisionHash = hash('sha256', $replacement->revision);
                if (in_array($revisionHash, $history, true)) {
                    return false;
                }
                $history[] = $revisionHash;
                $attributes = [
                    'mailbox_connection_id' => $connection->id, 'grant_id' => $context->grantId, 'lifecycle_generation' => $context->lifecycleGeneration,
                    'provider' => $replacement->identity->provider, 'issuer' => $replacement->identity->issuer,
                    'external_user_id' => $replacement->identity->subject, 'client_id' => $replacement->identity->clientId, 'tenant_id' => $replacement->identity->tenantId,
                    'access_token' => $replacement->accessToken(), 'refresh_token' => $replacement->refreshToken(), 'expires_at' => $replacement->expiresAt,
                    'scopes' => $replacement->scopes, 'grant_revision' => $replacement->revision, 'email' => $replacement->email, 'meta' => $replacement->metadata,
                    'nonce_hash' => $replacement->nonceHash(), 'grant_revision_history' => array_values(array_unique($history)),
                    'revoked_at' => null, 'last_refreshed_at' => now(),
                ];
                $replacementRecord = new MailboxOAuthToken($attributes);
                $replacementRecord->setUpdatedAt(now());
                if ($record === null) {
                    $replacementRecord->setCreatedAt(now());
                    $values = $replacementRecord->getAttributes();
                    $selection = MailboxConnection::query()->whereKey($context->connectionId)
                        ->where('selected_grant_id', $context->grantId)->where('lifecycle_generation', $context->lifecycleGeneration)
                        ->where('driver', $context->driver)->where('status', '!=', ConnectionStatus::DISABLED->value)
                        ->selectRaw(implode(', ', array_fill(0, count($values), '?')), array_values($values));

                    return MailboxOAuthToken::query()->toBase()->insertUsing(array_keys($values), $selection->toBase()) === 1;
                }

                return MailboxOAuthToken::query()->whereKey($record->id)->where('mailbox_connection_id', $context->connectionId)
                    ->where('grant_id', $context->grantId)->where('lifecycle_generation', $record->lifecycle_generation)
                    ->where('grant_revision', $record->grant_revision)->where('provider', $record->provider)
                    ->where('issuer', $record->issuer)->where('external_user_id', $record->external_user_id)
                    ->where('client_id', $record->client_id)->where('tenant_id', $record->tenant_id)
                    ->where('revoked_at', $record->revoked_at)
                    ->whereExists(function (Builder $query) use ($context): void {
                        $query->selectRaw('1')->from('mailbox_connections')->where('id', $context->connectionId)
                            ->where('selected_grant_id', $context->grantId)->where('lifecycle_generation', $context->lifecycleGeneration)
                            ->where('driver', $context->driver)->where('status', '!=', ConnectionStatus::DISABLED->value)->whereNull('deleted_at');
                    })->toBase()->update($replacementRecord->getAttributes()) === 1;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (Throwable) {
            throw new GrantRefreshException('The selected grant could not be saved safely.', true, 1);
        }
    }

    private function canonicalConnectionId(GrantContext $context): bool
    {
        return preg_match('/\A[1-9][0-9]*\z/', $context->connectionId) === 1;
    }

    private function grant(MailboxOAuthToken $record): ?StoredGrant
    {
        if ($record->issuer === null || $record->external_user_id === null || $record->client_id === null
            || $record->expires_at === null || $record->grant_revision === null) {
            return null;
        }

        return new StoredGrant(new GrantIdentity($record->provider, $record->issuer, $record->external_user_id, $record->client_id, $record->tenant_id),
            $record->access_token, $record->refresh_token, $record->expires_at->toDateTimeImmutable(), array_values($record->scopes ?? []),
            $record->grant_revision, $record->email, $record->meta ?? [], $record->nonce_hash);
    }
}
