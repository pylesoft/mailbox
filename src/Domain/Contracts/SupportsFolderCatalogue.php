<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\FolderCatalogue;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\Enums\WellKnownFolder;

interface SupportsFolderCatalogue
{
    /** @param list<WellKnownFolder> $wellKnownFolders */
    public function folderCatalogue(SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation = null, array $wellKnownFolders = []): FolderCatalogue;
}
