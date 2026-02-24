<?php

declare(strict_types=1);

use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\Importance;

it('creates a MessageDto from MS Graph response', function (): void {
    $dto = MessageDto::fromMsGraph(msgraphMessageFixture());

    expect($dto)
        ->id->toBe('AAMkAG')
        ->subject->toBe('Invoice #1234')
        ->from->address->toBe('vendor@example.com')
        ->isRead->toBeFalse()
        ->importance->toBe(Importance::NORMAL)
        ->internetMessageId->toBe('<msg-123@example.com>');
});

it('serializes to array and json', function (): void {
    $dto = MessageDto::fromMsGraph(msgraphMessageFixture());

    expect($dto->toArray())->toBeArray()->toHaveKeys(['id', 'subject', 'from']);
    expect(json_decode((string) json_encode($dto), true))->toHaveKey('id');
});
