<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;

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
 * `$envelope` has the delivery's `id` and `created_at` (null when built by
 * hand). Zapmizer retries a delivery up to 3 times, always with the same
 * `id`; the package only drops the repeats with
 * `zapmizer.webhook.deduplicate` on, so a listener with side effects must
 * otherwise be idempotent on `$message->id`.
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
        public ?Envelope $envelope = null,
    ) {
    }
}
