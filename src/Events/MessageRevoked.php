<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\MessageRevocation;

/**
 * Class MessageRevoked.
 *
 * Fired for a `message_revoked` webhook: a message was deleted.
 */
class MessageRevoked extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public MessageRevocation $revocation, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $revocation = MessageRevocation::fromData($payload['data'] ?? null);

        return $revocation === null ? null : new self($revocation, Envelope::fromPayload($payload), $connection, $payload);
    }
}
