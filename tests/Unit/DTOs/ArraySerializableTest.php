<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

it('normalizes nested values for arrays and json serialization', function (): void {
    $timestamp = CarbonImmutable::parse('2026-03-24T09:30:00-04:00');
    $payload = new class($timestamp) implements Arrayable, JsonSerializable
    {
        use ArraySerializable;

        public function __construct(
            public CarbonImmutable $timestamp,
        ) {}

        public ExampleState $state = ExampleState::READY;

        /** @var array<int, mixed> */
        public array $details = [];

        public function boot(): void
        {
            $this->details = [
                new class implements Arrayable
                {
                    public function toArray(): array
                    {
                        return ['kind' => 'arrayable-child'];
                    }
                },
                new class implements JsonSerializable
                {
                    public function jsonSerialize(): mixed
                    {
                        return ['kind' => 'json-child'];
                    }
                },
                ExampleState::FAILED,
                CarbonImmutable::parse('2026-03-24T10:00:00Z'),
            ];
        }
    };
    $payload->boot();

    expect($payload->toArray())->toBe([
        'timestamp' => $timestamp->jsonSerialize(),
        'state' => 'READY',
        'details' => [
            ['kind' => 'arrayable-child'],
            ['kind' => 'json-child'],
            'FAILED',
            CarbonImmutable::parse('2026-03-24T10:00:00Z')->jsonSerialize(),
        ],
    ])->and($payload->jsonSerialize())->toBe($payload->toArray());
});

enum ExampleState
{
    case READY;
    case FAILED;
}
