<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * An application that took over `message` (the 2024-02-27 event) by
 * overriding handleMessage(), the Cashier-style extension the docs teach.
 */
class OverridesHandleMessageWebhookController extends WebhookController
{
    public static int $handled = 0;

    protected function handleMessage(array $payload): Response
    {
        static::$handled++;

        return new Response('App Handled', 202);
    }
}
