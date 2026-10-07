<?php

namespace NotificationChannels\Zapmizer\Connect;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

final class PartnerSubscription
{
    public function __construct(
        public readonly int $userId,
        public readonly int $teamId,
        public readonly string $externalId,
        public readonly bool $subscribed,
        public readonly ?int $quantity,
        public readonly ?CarbonImmutable $trialEndsAt,
        public readonly bool $paymentIncomplete,
    ) {
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $payload, ?string $externalId = null): self
    {
        $userId = Payload::positiveId($payload['user_id'] ?? null);

        if ($userId === null) {
            throw ZapmizerConnectException::unexpectedResponse('invalid user_id');
        }

        $teamId = Payload::positiveId($payload['team_id'] ?? null);

        if ($teamId === null) {
            throw ZapmizerConnectException::unexpectedResponse('invalid team_id');
        }

        $answeredExternalId = $payload['external_id'] ?? null;

        if (!is_string($answeredExternalId) || $answeredExternalId === '') {
            throw ZapmizerConnectException::unexpectedResponse('invalid external_id');
        }

        return new self(
            userId: $userId,
            teamId: $teamId,
            externalId: $answeredExternalId,
            subscribed: ($payload['subscribed'] ?? null) === true,
            quantity: Payload::optionalCount($payload['quantity'] ?? null),
            trialEndsAt: Payload::date($payload['trial_ends_at'] ?? null, 'trial_ends_at', $externalId ?? $answeredExternalId),
            paymentIncomplete: ($payload['payment_incomplete'] ?? null) === true,
        );
    }

    public function hasAccess(?DateTimeInterface $now = null): bool
    {
        return $this->subscribed
            || ($this->trialEndsAt !== null && $this->trialEndsAt > ($now ?? CarbonImmutable::now()));
    }
}
