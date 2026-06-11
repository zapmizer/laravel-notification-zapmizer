<?php

namespace NotificationChannels\Zapmizer;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;

/**
 * Class VerificationSession.
 *
 * A hosted verification page session. Redirect the end user to `url` — a
 * signed, temporary URL on the Zapmizer domain where the whole verification
 * happens (wa.me trigger + code input). Nothing else to store.
 */
final class VerificationSession
{
    public function __construct(
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

        if (blank($data['url'] ?? null)) {
            throw ZapmizerVerificationException::unexpectedResponse('missing hosted page url');
        }

        return new self(
            url: (string) $data['url'],
            expiresAt: $data['expires_at'] ?? null,
            raw: $data,
        );
    }
}
