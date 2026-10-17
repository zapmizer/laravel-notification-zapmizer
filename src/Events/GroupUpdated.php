<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\GroupUpdate;

/**
 * Class GroupUpdated.
 *
 * Fired for a `group_updated` webhook: a group changed.
 */
class GroupUpdated extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public GroupUpdate $update, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $update = GroupUpdate::fromData($payload['data'] ?? null);

        return $update === null ? null : new self($update, Envelope::fromPayload($payload), $connection, $payload);
    }
}
