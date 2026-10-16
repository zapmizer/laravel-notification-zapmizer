<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use NotificationChannels\Zapmizer\Support\ApiError;

/**
 * Class MediaRateLimitedException.
 *
 * The media endpoint's rate limit was hit (429): 60 requests a minute per
 * user and instance. `retryAfter()` is Zapmizer's `Retry-After` in seconds
 * when it sent one. `ZapmizerConnection::awaitMedia()` waits it out;
 * `media()` propagates it.
 */
final class MediaRateLimitedException extends ZapmizerRateLimitedException
{
    public function __construct(ApiError $error)
    {
        parent::__construct(
            $error,
            'Zapmizer rate-limited the media request (60 a minute per user and instance).'
            . ($error->retryAfter !== null ? " Retry in {$error->retryAfter} s." : ''),
        );
    }
}
