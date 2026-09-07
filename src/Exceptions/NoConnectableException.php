<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Class NoConnectableException.
 *
 * The resolver found nothing to connect on this request (no authenticated
 * user, a user without a team, ...). `render` answers 403 with a stable
 * code; the popup callback catches it and reports through the result page
 * instead.
 */
final class NoConnectableException extends ZapmizerConnectException
{
    public function render(): JsonResponse
    {
        return new JsonResponse(['code' => 'no_connectable'], 403);
    }
}
