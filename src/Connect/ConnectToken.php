<?php

namespace NotificationChannels\Zapmizer\Connect;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

/**
 * Class ConnectToken.
 *
 * Result of exchanging the callback code: the team's Sanctum token on
 * Zapmizer, which team authorized, and — since the hosted page pairs the
 * number before handing out the code — the paired number, its instance and
 * the webhook Zapmizer registered for the session's `webhook_url`.
 *
 * `webhookSecret` is null when no webhook was requested AND when Zapmizer
 * reused a webhook the team already had for that URL (the secret is only
 * ever handed out once, on creation): a null secret with a non-null id
 * means "rotate to get one".
 */
final readonly class ConnectToken
{
    public function __construct(
        public string $token,
        public ?int $teamId = null,
        public ?string $teamName = null,
        public ?string $phoneNumber = null,
        public ?int $botInstanceId = null,
        public ?int $webhookId = null,
        public ?string $webhookSecret = null,
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

        return new self(
            token: (string) $data['token'],
            teamId: isset($data['team_id']) ? (int) $data['team_id'] : null,
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
}
