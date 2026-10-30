<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Support\ResponseBudget;

abstract class FakeGraphClient extends GraphClient
{
    /** @param array<string, mixed> $query */
    public function getBoundedObject(string $endpoint, ResponseBudget $budget, array $query = [], ?string $mailbox = null, bool $retry = false): \stdClass
    {
        return json_decode(json_encode((object) $this->get($endpoint, $query, $mailbox), JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function getBounded(string $endpoint, ResponseBudget $budget, array $query = [], ?string $mailbox = null, bool $retry = false): array
    {
        return $this->get($endpoint, $query, $mailbox);
    }

    public function streamBounded(string $endpoint, ResponseBudget $budget, int $maxBytes, ?string $mailbox = null): StreamInterface
    {
        return $this->stream($endpoint, $mailbox);
    }
}
