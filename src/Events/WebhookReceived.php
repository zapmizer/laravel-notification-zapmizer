<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Class WebhookReceived.
 *
 * Fired for every webhook that passes signature verification, before any
 * handling — mirror of Cashier's event of the same name.
 */
class WebhookReceived
{
    use Dispatchable;

    /**
     * @param array $payload The decoded webhook payload.
     */
    public function __construct(public array $payload)
    {
    }
}
