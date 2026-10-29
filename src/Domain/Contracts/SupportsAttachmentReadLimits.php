<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\AttachmentReadLimits;

interface SupportsAttachmentReadLimits
{
    public function withReadLimits(AttachmentReadLimits $limits): AttachmentResource;
}
