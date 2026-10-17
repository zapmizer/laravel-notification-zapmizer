<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\Transcription;

/**
 * Class MessageTranscribed.
 *
 * Fired for a `message_transcribed` webhook: an audio became text.
 */
class MessageTranscribed extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public Transcription $transcription, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $transcription = Transcription::fromData($payload['data'] ?? null);

        return $transcription === null ? null : new self($transcription, Envelope::fromPayload($payload), $connection, $payload);
    }
}
