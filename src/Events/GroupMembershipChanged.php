<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\GroupMembership;

/**
 * Class GroupMembershipChanged.
 *
 * Fired for a `group_membership` webhook: the number joined or left a group.
 */
class GroupMembershipChanged extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public GroupMembership $membership, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $membership = GroupMembership::fromData($payload['data'] ?? null);

        return $membership === null ? null : new self($membership, Envelope::fromPayload($payload), $connection, $payload);
    }
}
