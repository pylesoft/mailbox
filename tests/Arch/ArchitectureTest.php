<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\Importance;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\MailboxException;

arch('contracts are interfaces')
    ->expect('Pyle\\Mailbox\\Contracts')
    ->toBeInterfaces();

arch('DTOs are final readonly classes')
    ->expect([
        AttachmentDto::class,
        AttachmentFileDto::class,
        BodyDto::class,
        ConnectionTestResult::class,
        DeltaResultDto::class,
        EmailAddressDto::class,
        FolderDto::class,
        HealthCheckResult::class,
        MessageDto::class,
    ])
    ->toBeFinal()
    ->toBeReadonly();

arch('enums are enums')
    ->expect('Pyle\\Mailbox\\Enums')
    ->toBeEnums();

arch('models extend eloquent model')
    ->expect('Pyle\\Mailbox\\Models')
    ->toExtend(Model::class);

arch('events are final classes')
    ->expect('Pyle\\Mailbox\\Events')
    ->toBeFinal();

arch('exceptions extend mailbox exception')
    ->expect('Pyle\\Mailbox\\Exceptions')
    ->toExtend(MailboxException::class)
    ->ignoring(MailboxException::class);

arch('no debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('Pyle\\Mailbox')
    ->toUseStrictTypes();

test('enums are backed string enums', function (): void {
    $enums = [
        ConnectionStatus::class,
        SyncStatus::class,
        WellKnownFolder::class,
        Importance::class,
        MatchOperator::class,
        FilterableField::class,
    ];

    foreach ($enums as $enumClass) {
        $reflection = new ReflectionEnum($enumClass);

        expect($reflection->isBacked())->toBeTrue();
        expect($reflection->getBackingType()?->getName())->toBe('string');
    }
});
