<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use NotificationChannels\Zapmizer\Support\ApiError;

/**
 * Class MediaRejectedException.
 *
 * Zapmizer refused a media request (422): a `timestamp` in the future, or
 * an instance on Meta Cloud — there is no media endpoint for those. The
 * request will not do better on a retry. `reason()` is Zapmizer's own
 * explanation.
 */
final class MediaRejectedException extends ZapmizerApiException
{
    public function __construct(ApiError $error)
    {
        parent::__construct($error, "Zapmizer rejected the media request: {$error->reason()}");
    }
}
