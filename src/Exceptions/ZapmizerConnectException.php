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
     * Thrown when Zapmizer refused a media request (422): a `timestamp` in
     * the future, or an instance on Meta Cloud — there is no media endpoint
     * for those. The request will not do better on a retry.
     */
    public static function mediaRejected(string $reason): MediaRejectedException
    {
        return new MediaRejectedException($reason);
    }

    /**
     * Thrown when the media endpoint's rate limit was hit (429): 60 requests
     * a minute per user and instance. Retry after the given seconds.
     */
    public static function mediaRateLimited(?int $retryAfterSeconds = null): MediaRateLimitedException
    {
        return new MediaRateLimitedException($retryAfterSeconds);
    }

    /**
     * Thrown when Zapmizer responds with a payload we can't make sense of.
     */
    public static function unexpectedResponse(string $reason): self
    {
        return new self("Zapmizer returned an unexpected response. `{$reason}`");
    }
}
