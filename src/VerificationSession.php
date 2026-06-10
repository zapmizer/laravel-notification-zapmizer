<?php

namespace NotificationChannels\Zapmizer;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;

/**
 * Class VerificationSession.
 *
 * A hosted verification page session (Stripe billing-portal style). Created
 * server-side with the secret key; redirect the end user to `url`, where
 * they complete the whole verification on the Zapbot domain.
 */
final class VerificationSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly ?string $expiresAt = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a VerificationSession from a decoded API payload.
     *
     * @throws ZapmizerVerificationException
     */
    public static function fromPayload(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        if (blank($data['id'] ?? null) || blank($data['url'] ?? null)) {
            throw ZapmizerVerificationException::unexpectedResponse('missing session id or hosted page url');
        }

        return new self(
            id: (string) $data['id'],
            url: (string) $data['url'],
            expiresAt: $data['expires_at'] ?? null,
            raw: $data,
        );
    }
}
