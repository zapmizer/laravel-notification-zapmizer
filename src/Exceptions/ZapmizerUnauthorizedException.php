<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use NotificationChannels\Zapmizer\Support\ApiError;

/**
 * Class ZapmizerUnauthorizedException.
 *
 * 401 from Zapmizer: the stored team token was revoked on the other side,
 * or there was no token to send. Whoever catches it decides the response
 * (`reauth_required`) — the connection is never deactivated because of it.
 */
final class ZapmizerUnauthorizedException extends ZapmizerApiException
{
    public function __construct(ApiError $error, ?string $message = null)
    {
        parent::__construct($error, $message);
    }

    /**
     * No token to send, so no request was made: a 401 of our own, with no
     * response behind it (`error()` is null; `reason()` is the message).
     */
    public static function withoutResponse(string $message): self
    {
        return new self(ApiError::unauthenticated($message), $message);
    }
}
