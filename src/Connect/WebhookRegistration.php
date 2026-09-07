<?php

namespace NotificationChannels\Zapmizer\Connect;

/**
 * Class WebhookRegistration.
 *
 * The webhook as Zapmizer answers on creation and on secret rotation — the
 * only two responses that carry the secret. Whoever calls must persist it:
 * there is no way to read it back later.
 */
final readonly class WebhookRegistration
{
    public function __construct(
        public ?int $id,
        public ?string $secret,
        public ?string $previousSecret = null,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            secret: isset($data['secret']) ? (string) $data['secret'] : null,
            previousSecret: isset($data['previous_secret']) ? (string) $data['previous_secret'] : null,
        );
    }
}
