<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use Throwable;

/**
 * Class ZapmizerUnavailableException.
 *
 * Zapmizer is down (timeout/5xx) or there was no answer at all. It does not
 * render: the app's exception handler decides the response.
 */
final class ZapmizerUnavailableException extends ZapmizerConnectException
{
    public static function dueTo(?Throwable $exception = null): self
    {
        return new self('Zapmizer is unavailable.', 0, $exception);
    }
}
