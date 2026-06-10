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
     * Thrown when there's no publishable key provided.
     */
    public static function publishableKeyNotProvided(): self
    {
        return new self('You must provide your zapmizer publishable key (pk_...) to use the verify-number API. Set ZAPMIZER_PUBLISHABLE_KEY — this is not the same credential as the messages API token.');
    }

    /**
     * Thrown when the model has no WhatsApp number to verify.
     */
    public static function numberNotProvided(): self
    {
        return new self('There is no WhatsApp number to verify. Override getWhatsappNumberForVerification() on your model to point at the right attribute.');
    }

    /**
     * Thrown when trying to act on a verification that was never started.
     */
    public static function verificationNotStarted(): self
    {
        return new self('There is no WhatsApp verification in progress. Call startWhatsappVerification() first.');
    }

    /**
     * Thrown when Zapmizer responds with a payload we can't make sense of.
     */
    public static function unexpectedResponse(string $reason): self
    {
        return new self("Zapmizer returned an unexpected response. `{$reason}`");
    }
}
