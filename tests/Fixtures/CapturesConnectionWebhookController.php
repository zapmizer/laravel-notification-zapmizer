<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use Symfony\Component\HttpFoundation\Response;

/**
 * An app-extended controller with a handler for an event the package does
 * not handle — what `$this->connection()` gives it is the point.
 */
class CapturesConnectionWebhookController extends WebhookController
{
    public static ?ZapmizerConnection $seen = null;

    public static bool $handled = false;

    protected function handleQr(array $payload): Response
    {
        static::$seen = $this->connection();
        static::$handled = true;

        return $this->successMethod();
    }
}
