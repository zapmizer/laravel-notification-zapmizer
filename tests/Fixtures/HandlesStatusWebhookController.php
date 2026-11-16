<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * An application that extended the controller with its own handler for
 * `message_status` before the package fired MessageStatusUpdated.
 */
class HandlesStatusWebhookController extends WebhookController
{
    public static int $handled = 0;

    protected function handleMessageStatus(array $payload): Response
    {
        static::$handled++;

        return new Response('App Handled', 202);
    }
}
