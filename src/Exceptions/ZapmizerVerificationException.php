<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use Exception;

/**
 * Class ZapmizerVerificationException.
 *
 * Base exception for the Zapmizer verification client. Catch this to handle
 * any failure raised while creating or fetching a verification.
 */
class ZapmizerVerificationException extends Exception
{
    /**
     * Thrown when there's no API token provided.
     */
    public static function apiTokenNotProvided(): self
    {
        return new self('You must provide your zapmizer API token to make any API requests.');
    }

    /**
     * Thrown when Zapmizer responds with a payload we can't make sense of.
     */
    public static function unexpectedResponse(string $reason): self
    {
        return new self("Zapmizer returned an unexpected response. `{$reason}`");
    }
}
