<?php

namespace NotificationChannels\Zapmizer\Exceptions;

/**
 * Class MediaRateLimitedException.
 *
 * The media endpoint's rate limit was hit (429): 60 requests a minute per
 * user and instance. `retryAfter()` is Zapmizer's `Retry-After` in seconds
 * when it sent one. `ZapmizerConnection::awaitMedia()` waits it out;
 * `media()` propagates it.
 */
final class MediaRateLimitedException extends ZapmizerConnectException
{
    public function __construct(private readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct(
            'Zapmizer rate-limited the media request (60 a minute per user and instance).'
            . ($retryAfterSeconds !== null ? " Retry in {$retryAfterSeconds} s." : '')
        );
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
