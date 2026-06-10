<?php

namespace NotificationChannels\Zapmizer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Support\ZapbotSignature;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Class VerifyWebhookSignature.
 *
 * Rejects webhook deliveries whose X-Zapbot-Signature header doesn't match
 * the HMAC of the raw body with the configured webhook secret.
 */
class VerifyWebhookSignature
{
    /**
     * Handle the incoming request.
     *
     * @throws AccessDeniedHttpException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $valid = ZapbotSignature::isValid(
            (string) $request->header('X-Zapbot-Signature', ''),
            $request->getContent(),
            (string) config('zapmizer.webhook_secret'),
        );

        if (!$valid) {
            throw new AccessDeniedHttpException('Invalid webhook signature.');
        }

        return $next($request);
    }
}
