<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\StatusUpdate;

/**
 * Class MessageStatusUpdated.
 *
 * Fired for a `message_status` webhook: a message sent by the number changed
 * status. Not fired for a status named `unknown` or an `ack` off the scale.
 * Match `$status->wappMessageId` against the messages you sent and ignore
 * the ones you do not know (the phone's own sends have statuses too).
 */
class MessageStatusUpdated extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public StatusUpdate $status, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $status = StatusUpdate::fromData($payload['data'] ?? null);

        return $status === null ? null : new self($status, Envelope::fromPayload($payload), $connection, $payload);
    }
}
