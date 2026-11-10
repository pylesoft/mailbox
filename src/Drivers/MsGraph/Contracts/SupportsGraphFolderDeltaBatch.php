<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph\Contracts;

use Pyle\Mailbox\Contracts\SupportsIncrementalSync;
use Pyle\Mailbox\DTOs\ChangePage;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\Exceptions\MailboxException;

interface SupportsGraphFolderDeltaBatch extends SupportsIncrementalSync
{
    /**
     * Read one incremental page for each of up to three folder checkpoints.
     *
     * The item limit applies to each folder. The byte limit applies to the complete batch response.
     *
     * @param  array<int, SyncCheckpoint>  $checkpoints
     * @return array<int, ChangePage|MailboxException>
     */
    public function incrementalSyncBatch(#[\SensitiveParameter] array $checkpoints, int $maxItemsPerFolder = 100, int $maxResponseBytes = 8_388_608): array;
}
