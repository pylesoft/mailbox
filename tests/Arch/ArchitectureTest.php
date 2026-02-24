<?php

declare(strict_types=1);

use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\DTOs\MessageDto;

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
    ->toExtend(\Illuminate\Database\Eloquent\Model::class);

arch('events are final classes')
    ->expect('Pyle\\Mailbox\\Events')
    ->toBeFinal();

arch('exceptions extend mailbox exception')
    ->expect('Pyle\\Mailbox\\Exceptions')
    ->toExtend(\Pyle\Mailbox\Exceptions\MailboxException::class)
    ->ignoring(\Pyle\Mailbox\Exceptions\MailboxException::class);

arch('no debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('Pyle\\Mailbox')
    ->toUseStrictTypes();
