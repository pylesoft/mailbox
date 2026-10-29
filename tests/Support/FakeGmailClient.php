<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Support\ResponseBudget;

abstract class FakeGmailClient extends GmailClient
{
    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getBounded(string $endpoint, ResponseBudget $budget, array $query = [], ?string $mailbox = null): array
    {
        return $this->get($endpoint, $query, $mailbox);
    }
}
