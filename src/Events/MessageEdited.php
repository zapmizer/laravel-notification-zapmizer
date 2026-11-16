<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\MessageEdit;

/**
 * Class MessageEdited.
 *
 * Fired for a `message_edited` webhook: a message was edited.
 */
class MessageEdited extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public MessageEdit $edit, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $edit = MessageEdit::fromData($payload['data'] ?? null);

        return $edit === null ? null : new self($edit, Envelope::fromPayload($payload), $connection, $payload);
    }
}
