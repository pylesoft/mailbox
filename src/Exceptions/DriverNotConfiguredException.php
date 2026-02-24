<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class DriverNotConfiguredException extends MailboxException
{
    /**
     * @param  array<string>  $available
     */
    public static function forDriver(string $driver, array $available, ?\Throwable $previous = null): self
    {
        $message = sprintf(
            "No configuration found for driver '%s'. Add it to config/mailbox.php. Available drivers: %s.",
            $driver,
            $available !== [] ? implode(', ', $available) : 'none',
        );

        return new self($message, 0, $previous);
    }
}
