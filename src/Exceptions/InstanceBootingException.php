<?php

namespace NotificationChannels\Zapmizer\Exceptions;

/**
 * Class InstanceBootingException.
 *
 * 423 from Zapmizer: an instance boot is already in progress. The frontend
 * should wait and retry — not a user error. `$instanceId` is the instance
 * Zapmizer reports as booting, when it does: the retry must reuse it.
 */
final class InstanceBootingException extends ZapmizerConnectException
{
    public ?int $instanceId = null;

    public static function inProgress(?int $instanceId = null): self
    {
        $exception = new self('An instance boot is already in progress.');
        $exception->instanceId = $instanceId;

        return $exception;
    }
}
