<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;

/**
 * Class WebhookEvent.
 *
 * Base of the typed events of the webhook catalog (`message_status`,
 * `connection`, reactions, groups, ...). Each one is fired right after
 * WebhookReceived, whatever handler the controller has for the event, and
 * only when its `data` can be read. The package persists nothing.
 *
 * `$envelope` has the event's `id` (the same on every retry) and
 * `created_at`; `$connection` is the connection whose secret signed the
 * delivery — null for the single-tenant secret; `$payload` is the decoded
 * body.
 */
abstract class WebhookEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param array<string, mixed> $payload The decoded webhook payload.
     */
    public function __construct(
        public Envelope $envelope,
        public ?ZapmizerConnection $connection,
        public array $payload,
    ) {
    }

    /**
     * Build the event from a delivery; null when its `data` cannot be read.
     *
     * @param array<string, mixed> $payload
     */
    abstract public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self;
}
