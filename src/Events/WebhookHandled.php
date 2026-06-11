<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Class WebhookHandled.
 *
 * Fired after a webhook was handled by one of the controller's handlers —
 * mirror of Cashier's event of the same name.
 */
class WebhookHandled
{
    use Dispatchable;

    /**
     * @param array $payload The decoded webhook payload.
     */
    public function __construct(public array $payload)
    {
    }
}
