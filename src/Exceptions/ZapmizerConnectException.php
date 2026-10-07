<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use NotificationChannels\Zapmizer\Support\ApiError;

/**
 * Class ZapmizerConnectException.
 *
 * Base exception of the connect flow (partner authorization, instance
 * pairing, webhook registration). Catch this to handle any of them.
 */
class ZapmizerConnectException extends ZapmizerException
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
     * for those. The request will not do better on a retry. Carries the
     * `ApiError` of the answer; `reason()` is Zapmizer's own explanation.
     */
    public static function mediaRejected(ApiError $error): MediaRejectedException
    {
        return new MediaRejectedException($error);
    }

    /**
     * Thrown when the media endpoint's rate limit was hit (429): 60 requests
     * a minute per user and instance. Carries the `ApiError` of the answer;
     * `retryAfter()` is its `Retry-After` header, in seconds, when present.
     */
    public static function mediaRateLimited(ApiError $error): MediaRateLimitedException
    {
        return new MediaRateLimitedException($error);
    }

    public static function notPaired(): self
    {
        return new self('There is no paired Zapmizer instance for this connection.');
    }

    public static function unreadableFile(string $path): self
    {
        return new self("Could not open {$path} to send.");
    }

    /**
     * Thrown when Zapmizer responds with a payload we can't make sense of.
     */
    public static function unexpectedResponse(string $reason): self
    {
        return new self("Zapmizer returned an unexpected response. `{$reason}`");
    }
}
