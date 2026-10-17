<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\ContactNumberChange;

/**
 * Class ContactNumberChanged.
 *
 * Fired for a `contact_number_changed` webhook: a contact changed number.
 */
class ContactNumberChanged extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public ContactNumberChange $change, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $change = ContactNumberChange::fromData($payload['data'] ?? null);

        return $change === null ? null : new self($change, Envelope::fromPayload($payload), $connection, $payload);
    }
}
