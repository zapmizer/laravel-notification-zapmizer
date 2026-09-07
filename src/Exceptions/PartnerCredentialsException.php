<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Class PartnerCredentialsException.
 *
 * Zapmizer refused the partner key (401/403): wrong, revoked or missing
 * from the config. A configuration defect on our side, not the user's — but
 * they need a screen, not "Server Error". `render` answers a stable code and
 * nothing else; Zapmizer's message (which may carry a stack trace when
 * debug is on over there) stays in the log.
 */
final class PartnerCredentialsException extends ZapmizerConnectException
{
    public function render(): JsonResponse
    {
        return new JsonResponse(['code' => 'partner_unauthorized'], 503);
    }
}
