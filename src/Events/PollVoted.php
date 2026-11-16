<?php

namespace NotificationChannels\Zapmizer\Events;

use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;
use NotificationChannels\Zapmizer\Webhook\PollVote;

/**
 * Class PollVoted.
 *
 * Fired for a `poll_vote` webhook: someone voted on a poll sent by the number.
 */
class PollVoted extends WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public PollVote $vote, Envelope $envelope, ?ZapmizerConnection $connection, array $payload)
    {
        parent::__construct($envelope, $connection, $payload);
    }

    public static function fromWebhook(array $payload, ?ZapmizerConnection $connection): ?self
    {
        $vote = PollVote::fromData($payload['data'] ?? null);

        return $vote === null ? null : new self($vote, Envelope::fromPayload($payload), $connection, $payload);
    }
}
