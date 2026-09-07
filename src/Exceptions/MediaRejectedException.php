<?php

namespace NotificationChannels\Zapmizer\Exceptions;

/**
 * Class MediaRejectedException.
 *
 * Zapmizer refused a media request (422): a `timestamp` in the future, or
 * an instance on Meta Cloud — there is no media endpoint for those. The
 * request will not do better on a retry. `reason()` is Zapmizer's own
 * explanation.
 */
final class MediaRejectedException extends ZapmizerConnectException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct("Zapmizer rejected the media request: {$reason}");
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
