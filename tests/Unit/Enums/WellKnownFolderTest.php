<?php

declare(strict_types=1);

use Pyle\Mailbox\Enums\WellKnownFolder;

it('maps to graph folder names', function (): void {
    expect(WellKnownFolder::INBOX->forDriver('ms-graph'))->toBe('Inbox');
    expect(WellKnownFolder::SENT->forDriver('ms-graph'))->toBe('SentItems');
});
