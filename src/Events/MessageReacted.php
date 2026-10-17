<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\Reaction;

/**
 * Class MessageReacted.
 *
 * Fired for a `message_reacted` webhook: someone reacted to a message, or
 * removed the reaction.
 */
class MessageReacted extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public Reaction $reaction, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $reaction = Reaction::fromData($payload['data'] ?? null);

        return $reaction === null ? null : new self($reaction, Envelope::fromPayload($payload), $connection, $payload);
    }
}
