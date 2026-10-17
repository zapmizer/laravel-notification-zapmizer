<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\ConnectionState;
use NotificationChannels\Zapmizer\Webhook\Envelope;

/**
 * Class ConnectionStateChanged.
 *
 * Fired for a `connection` webhook: the number connected, dropped or is
 * waiting for a QR. The package stores no state — two rules for the
 * listener that does:
 *
 * - without a `wid` (`isAttributable()` false: `qr_pending`, `connecting`,
 *   sometimes others) there is no telling which number it is about: do not
 *   change any state, confirm through the API (`is_online`);
 * - every occurrence has its own `id`, and they may arrive out of order:
 *   apply only an event whose `envelope->createdAt` is newer than the last
 *   one you applied.
 */
class ConnectionStateChanged extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public ConnectionState $state, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $state = ConnectionState::fromData($payload['data'] ?? null);

        return $state === null ? null : new self($state, Envelope::fromPayload($payload), $connection, $payload);
    }

    /**
     * Whether the event says which number it is about (`wid` present).
     */
    public function isAttributable(): bool
    {
        return $this->envelope->wid !== null;
    }
}
