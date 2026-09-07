<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use Exception;

/**
 * Class ZapmizerConnectException.
 *
 * Base exception of the connect flow (partner authorization, instance
 * pairing, webhook registration). Catch this to handle any of them.
 */
class ZapmizerConnectException extends Exception
{
    /**
     * Thrown when a Connectable model has no active Zapmizer connection.
     */
    public static function notConnected(): self
    {
        return new self('This model has no active Zapmizer connection. Complete the connect flow first.');
    }

    /**
     * Thrown when the resolved model is not a Connectable — a configuration
     * error of `zapmizer.connect.resolver`, not a user error.
     */
    public static function notConnectable(object $model): self
    {
        return new self(sprintf(
            '%s must implement NotificationChannels\Zapmizer\Contracts\Connectable (use the NotificationChannels\Zapmizer\Connectable trait) to be connected.',
            get_class($model),
        ));
    }

    /**
     * Thrown by a resolver that has nothing to connect on the request (no
     * authenticated user, a user without a team). Renders 403 with the code
     * `no_connectable`; the popup callback reports it on the result page.
     */
    public static function noConnectable(?string $reason = null): NoConnectableException
    {
        return new NoConnectableException($reason ?? 'There is nothing to connect on this request.');
    }

    /**
     * Thrown when the partner credentials are missing from the config.
     */
    public static function partnerCredentialsNotProvided(): self
    {
        return new self('You must set zapmizer.partner.id and zapmizer.partner.secret to use the connect flow.');
    }

    /**
     * Thrown when Zapmizer responds with a payload we can't make sense of.
     */
    public static function unexpectedResponse(string $reason): self
    {
        return new self("Zapmizer returned an unexpected response. `{$reason}`");
    }
}
