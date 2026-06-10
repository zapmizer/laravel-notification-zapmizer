<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use Throwable;

/**
 * Class VerificationConnectionFailed.
 *
 * Thrown when we're unable to reach Zapmizer at all (timeout, DNS failure,
 * connection refused, ...).
 */
final class VerificationConnectionFailed extends ZapmizerVerificationException
{
    public static function dueTo(Throwable $exception): self
    {
        return new self("The communication with Zapmizer failed. `{$exception->getMessage()}`", 0, $exception);
    }
}
