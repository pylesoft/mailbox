<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;

interface AttachmentResource
{
    public function metadata(): AttachmentDto;

    public function download(): AttachmentFileDto;

    public function stream(): StreamInterface;
}
