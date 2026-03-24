<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Facades;

use Illuminate\Support\Facades\Facade;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\MailboxManager;
use Pyle\Mailbox\Models\Folder;
use Pyle\Mailbox\Models\Mailbox as MailboxModel;
use Pyle\Mailbox\Models\MailboxMessage;

/**
 * @method static MailboxDriver driver(string $name = null)
 * @method static MailboxResource mailbox(string $emailAddress)
 * @method static MailboxResource forMailbox(MailboxModel $mailbox)
 * @method static \Pyle\Mailbox\Contracts\FolderResource forFolder(Folder $folder)
 * @method static ConnectionTestResult testConnection(?string $emailAddress = null)
 * @method static HealthCheckResult healthCheck()
 * @method static \Illuminate\Support\Collection<int, MailboxMessage> syncMailbox(MailboxModel $mailbox, array<string, mixed> $options = [])
 * @method static MailboxMessage moveMessage(MailboxMessage $message, string|WellKnownFolder $destinationFolder)
 * @method static \Illuminate\Support\Collection<int, array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}> listFolderTree(MailboxModel $mailbox, int $maxDepth = 10)
 * @method static array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}|null findFolderByName(MailboxModel $mailbox, string $folderName, string|WellKnownFolder|null $root = null, bool $caseSensitive = true)
 * @method static \Illuminate\Support\Collection<int, FilterableField> filterableFields()
 *
 * @see MailboxManager
 */
class Mailbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MailboxManager::class;
    }
}
