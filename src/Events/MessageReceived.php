<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;

/**
 * Class MessageReceived.
 *
 * Fired for every `message` webhook event. Like Cashier, the package
 * persists nothing — the application listens and decides what the message
 * means (route it, store it, reply through `$connection->message()`).
 *
 * `$connection` is the connection whose secret signed the delivery — null
 * when the delivery was signed with the application's own secret
 * (`zapmizer.webhook.secret`, single-tenant setup).
 *
 * Zapmizer retries a delivery up to 3 times, and the package does not
 * deduplicate: a listener with side effects must be idempotent on
 * `$message->id`.
 */
class MessageReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param array $payload The decoded webhook payload.
     */
    public function __construct(
        public InboundMessage $message,
        public ?ZapmizerConnection $connection,
        public array $payload,
    ) {
    }
}
