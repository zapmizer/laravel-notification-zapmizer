<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\GroupAdminsChange;

/**
 * Class GroupAdminsChanged.
 *
 * Fired for a `group_admins_changed` webhook: a group's admins changed.
 */
class GroupAdminsChanged extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public GroupAdminsChange $change, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $change = GroupAdminsChange::fromData($payload['data'] ?? null);

        return $change === null ? null : new self($change, Envelope::fromPayload($payload), $connection, $payload);
    }
}
