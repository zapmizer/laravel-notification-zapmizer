<?php

namespace NotificationChannels\Zapmizer;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;

/**
 * Class Verification.
 *
 * Immutable representation of a verification as returned by the Zapmizer API.
 */
final class Verification
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $url = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a Verification from a decoded API payload.
     *
     * @throws ZapmizerVerificationException
     */
    public static function fromPayload(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        $id = $data['id'] ?? null;

        if (blank($id)) {
            throw ZapmizerVerificationException::unexpectedResponse('missing verification identifier');
        }

        return new self(
            id: (string) $id,
            status: (string) ($data['status'] ?? 'pending'),
            url: $data['url'] ?? $data['hosted_page_url'] ?? null,
            raw: $data,
        );
    }
}
