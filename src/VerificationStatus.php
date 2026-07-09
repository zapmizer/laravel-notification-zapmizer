<?php

namespace NotificationChannels\Zapmizer;

/**
 * Class VerificationStatus.
 *
 * Outcome of a pending() call: whether the bot signal for a number has already
 * landed (a live, non-expired code exists) and how many seconds of TTL it has
 * left. It is the gate a verification UI checks before unlocking the code input
 * — while the code the bot generated is still propagating, `pending` is false,
 * so the end user cannot confirm too early and hit the propagation race.
 */
final class VerificationStatus
{
    public function __construct(
        public readonly bool $pending,
        public readonly ?int $secondsLeft = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a VerificationStatus from a decoded API payload.
     */
    public static function fromPayload(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        return new self(
            pending: (bool) ($data['pending'] ?? false),
            secondsLeft: isset($data['seconds_left']) ? (int) $data['seconds_left'] : null,
            raw: $data,
        );
    }

    public function isPending(): bool
    {
        return $this->pending;
    }
}
