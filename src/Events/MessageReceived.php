<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\Messages\Message;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;

/**
 * Class MessageReceived.
 *
 * A message reached the number: the `message_received` webhook event
 * (version `2026-10-20`) or, from a webhook still in `2024-02-27`, the
 * `message` event, translated into the same `Message`. A notice of WhatsApp
 * that is not a message (someone joining a group, a deleted message...)
 * fires nothing, as in the contract. Like Cashier, the package persists
 * nothing — the application listens and decides what the message means
 * (route it, store it, reply through `$connection->message()`).
 *
 * `$connection` is the connection whose secret signed the delivery — null
 * when the delivery was signed with the application's own secret
 * (`zapmizer.webhook.secret`, single-tenant setup). The number that
 * received the message is `$envelope->wid`.
 *
 * `$envelope` has the delivery's `id`, `created_at`, `wid` and `version`
 * (null when built by hand). Zapmizer retries a delivery up to 3 times,
 * always with the same `id`; the package only drops the repeats with
 * `zapmizer.webhook.deduplicate` on, so a listener with side effects must
 * otherwise be idempotent on `$message->chat` + `$message->id`.
 */
class MessageReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param array $payload The decoded webhook payload.
     */
    public function __construct(
        public Message $message,
        public ?ZapmizerConnection $connection,
        public array $payload,
        public ?Envelope $envelope = null,
    ) {
    }
}
