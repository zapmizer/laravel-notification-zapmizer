<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;

/**
 * An application that turned the typed catalog events off.
 */
class WithoutCatalogEventsWebhookController extends WebhookController
{
    protected function catalogEvents(): array
    {
        return [];
    }
}
