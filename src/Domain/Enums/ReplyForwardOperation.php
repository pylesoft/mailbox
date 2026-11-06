<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum ReplyForwardOperation: string
{
    case Reply = 'reply';
    case Forward = 'forward';
}
