<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Class ZapmizerUnavailableException.
 *
 * Zapmizer is down (timeout/5xx). `render` centralizes the connect-flow
 * response: any controller that lets it bubble answers 503 with a stable
 * code for the frontend to back off on.
 */
final class ZapmizerUnavailableException extends ZapmizerConnectException
{
    public static function dueTo(?Throwable $exception = null): self
    {
        return new self('Zapmizer is unavailable.', 0, $exception);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['code' => 'zapmizer_unavailable'], 503);
    }
}
