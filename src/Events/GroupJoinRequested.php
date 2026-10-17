<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\GroupJoinRequest;

/**
 * Class GroupJoinRequested.
 *
 * Fired for a `group_join_request` webhook: someone asked to join a group.
 */
class GroupJoinRequested extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public GroupJoinRequest $joinRequest, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $joinRequest = GroupJoinRequest::fromData($payload['data'] ?? null);

        return $joinRequest === null ? null : new self($joinRequest, Envelope::fromPayload($payload), $connection, $payload);
    }
}
