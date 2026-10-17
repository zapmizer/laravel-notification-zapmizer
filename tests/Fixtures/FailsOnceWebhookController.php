<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * An application handler that answers 503 the first time (a temporary
 * failure of its own, so Zapmizer retries) and succeeds after.
 */
class FailsOnceWebhookController extends WebhookController
{
    public static int $calls = 0;

    protected function handleMessageStatus(array $payload): Response
    {
        return ++static::$calls === 1 ? new Response('Try again', 503) : $this->successMethod();
    }
}
