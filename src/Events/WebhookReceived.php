<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;

/**
 * Class WebhookReceived.
 *
 * Fired for every webhook that passes signature verification, before any
 * handling — mirror of Cashier's event of the same name.
 */
class WebhookReceived
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
