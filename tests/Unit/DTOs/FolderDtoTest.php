<?php

declare(strict_types=1);

use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

it('maps well known folders from ms graph', function (): void {
    $dto = FolderDto::fromMsGraph([
        'id' => 'inbox',
        'displayName' => 'Inbox',
        'childFolderCount' => 0,
        'totalItemCount' => 1,
        'unreadItemCount' => 0,
    ]);

    expect($dto->wellKnownName)->toBe(WellKnownFolder::INBOX);
});
