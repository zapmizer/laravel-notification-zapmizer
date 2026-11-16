<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\IncomingCall;

/**
 * Class CallReceived.
 *
 * Fired for an `incoming_call` webhook: someone is calling the number.
 */
class CallReceived extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public IncomingCall $call, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $call = IncomingCall::fromData($payload['data'] ?? null);

        return $call === null ? null : new self($call, Envelope::fromPayload($payload), $connection, $payload);
    }
}
