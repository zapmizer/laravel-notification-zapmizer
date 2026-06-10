<?php

namespace NotificationChannels\Zapmizer;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;

/**
 * Class Verification.
 *
 * Immutable representation of a verify-number session as returned by the
 * Zapbot API. The flow is inverted: the end user opens the wa.me link
 * (`waLink`), sends the opening message, receives a code on WhatsApp and
 * types it back (see VerificationClient::confirm()).
 *
 * Statuses: resolving → pending → received → verified | expired | failed.
 */
final class Verification
{
    public function __construct(
        public readonly string $number,
        public readonly string $status,
        public readonly ?string $waLink = null,
        public readonly ?string $verifiedAt = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $failureReason = null,
        public readonly ?string $clientReference = null,
        public readonly ?int $codeLength = null,
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

        $number = $data['number'] ?? null;

        if (blank($number)) {
            throw ZapmizerVerificationException::unexpectedResponse('missing verification number');
        }

        return new self(
            number: (string) $number,
            status: (string) ($data['status'] ?? 'pending'),
            waLink: $data['wa_link'] ?? null,
            verifiedAt: $data['verified_at'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            failureReason: $data['failure_reason'] ?? null,
            clientReference: $data['client_reference'] ?? null,
            codeLength: isset($data['code_length']) ? (int) $data['code_length'] : null,
            raw: $data,
        );
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['verified', 'expired', 'failed'], true);
    }
}
