<?php

namespace NotificationChannels\Zapmizer\Connect;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * Class ConnectToken.
 *
 * Result of exchanging the callback code: the team's Sanctum token on
 * Zapmizer, the user and team that authorized it, and — since the hosted
 * page pairs the number before handing out the code — the paired number,
 * its instance and the webhook Zapmizer registered for the session's
 * `webhook_url`. `hasNumber()` is false when the number ceased to exist
 * between the approval and the exchange.
 *
 * `webhookSecret` is null when no webhook was requested AND when Zapmizer
 * reused a webhook the team already had for that URL (the secret is only
 * ever handed out once, on creation): a null secret with a non-null id
 * means "rotate to get one".
 */
final class ConnectToken
{
    public function __construct(
        public readonly string $token,
        public readonly int $userId,
        public readonly int $teamId,
        public readonly ?string $teamName = null,
        public readonly ?string $phoneNumber = null,
        public readonly ?int $botInstanceId = null,
        public readonly ?int $webhookId = null,
        public readonly ?string $webhookSecret = null,
    ) {
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        if (blank($data['token'] ?? null)) {
            throw ZapmizerConnectException::unexpectedResponse('missing token');
        }

        $userId = Payload::positiveId($data['user_id'] ?? null);

        if ($userId === null) {
            throw ZapmizerConnectException::unexpectedResponse('invalid user_id');
        }

        $teamId = Payload::positiveId($data['team_id'] ?? null);

        if ($teamId === null) {
            throw ZapmizerConnectException::unexpectedResponse('invalid team_id');
        }

        return new self(
            token: (string) $data['token'],
            userId: $userId,
            teamId: $teamId,
            teamName: isset($data['team_name']) ? (string) $data['team_name'] : null,
            phoneNumber: filled($data['phone_number'] ?? null) ? (string) $data['phone_number'] : null,
            botInstanceId: isset($data['bot_instance_id']) ? (int) $data['bot_instance_id'] : null,
            webhookId: isset($data['webhook_id']) ? (int) $data['webhook_id'] : null,
            webhookSecret: filled($data['webhook_secret'] ?? null) ? (string) $data['webhook_secret'] : null,
        );
    }

    /**
     * Zapmizer registered (or reused) a webhook but did not hand the secret
     * out: the caller must rotate to obtain one before any delivery can be
     * verified.
     */
    public function needsWebhookSecret(): bool
    {
        return $this->webhookId !== null && $this->webhookSecret === null;
    }

    public function hasNumber(): bool
    {
        return $this->phoneNumber !== null && $this->botInstanceId !== null;
    }
}
