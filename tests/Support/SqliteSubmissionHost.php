<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use PDO;
use Pyle\Mailbox\Contracts\GrantStore;
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;

/** A test host's private durable store, separate from all Mailbox tables. */
final class SqliteSubmissionHost implements GrantStore, GrantTokenProvider
{
    private PDO $database;

    public function __construct(string $path)
    {
        $this->database = new PDO('sqlite:'.$path);
        $this->database->exec('CREATE TABLE IF NOT EXISTS host_state (identity TEXT PRIMARY KEY, payload BLOB NOT NULL)');
    }

    public function bind(GrantContext $context): void
    {
        $this->retain('binding', $context);
    }

    public function assertActive(GrantContext $context): void
    {
        if ($this->restore('binding') != $context) {
            throw new GrantAccessDeniedException('The host superseded the selected authorization.');
        }
    }

    public function load(GrantContext $context): ?StoredGrant
    {
        $this->assertActive($context);

        return $this->restore('grant');
    }

    public function replace(GrantContext $context, ?StoredGrant $expected, StoredGrant $replacement): bool
    {
        $this->database->beginTransaction();
        try {
            $current = $this->load($context);
            if (($expected === null) !== ($current === null)
                || ($expected !== null && ($current->revision !== $expected->revision || ! $current->identity->matches($expected->identity)))) {
                $this->database->rollBack();

                return false;
            }
            $this->retain('grant', $replacement);
            $this->database->commit();

            return true;
        } catch (\Throwable $exception) {
            $this->database->rollBack();

            throw $exception;
        }
    }

    public function accessToken(GrantContext $context): string
    {
        $grant = $this->load($context);
        if ($grant === null || $grant->expiresAt <= new \DateTimeImmutable) {
            throw new GrantAccessDeniedException('The host requires current authorization.');
        }

        return $grant->accessToken();
    }

    public function retain(string $identity, mixed $value): void
    {
        $statement = $this->database->prepare('INSERT INTO host_state VALUES (?, ?) ON CONFLICT(identity) DO UPDATE SET payload = excluded.payload');
        $statement->execute([$identity, serialize($value)]);
    }

    public function restore(string $identity): mixed
    {
        $statement = $this->database->prepare('SELECT payload FROM host_state WHERE identity = ?');
        $statement->execute([$identity]);
        $payload = $statement->fetchColumn();

        return is_string($payload) ? unserialize($payload) : null;
    }
}
