<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;

/**
 * Class WebhookHandled.
 *
 * Fired after a webhook was handled by one of the controller's handlers —
 * mirror of Cashier's event of the same name.
 */
class WebhookHandled
{
    use Dispatchable, SerializesModels;

    /**
     * @param array $payload The decoded webhook payload.
     * @param ZapmizerConnection|null $connection The connection whose secret signed the delivery, when known.
     */
    public function __construct(public array $payload, public ?ZapmizerConnection $connection = null)
    {
    }
}
