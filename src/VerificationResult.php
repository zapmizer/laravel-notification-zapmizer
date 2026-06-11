<?php

namespace NotificationChannels\Zapmizer;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;

/**
 * Class VerificationResult.
 *
 * Outcome of a confirm call. `verified` consumes the code (single use);
 * `invalid` carries the attempts left before lockout; `failed` means the
 * attempts were exhausted; `not_found` covers no-active-code situations —
 * including the short window while the code is still propagating, so treat
 * it as "retry in a few seconds", not a hard error.
 */
final class VerificationResult
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_FOUND = 'not_found';

    public function __construct(
        public readonly string $status,
        public readonly ?string $number = null,
        public readonly ?string $from = null,
        public readonly ?int $attemptsLeft = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a VerificationResult from a decoded API payload.
     *
     * @throws ZapmizerVerificationException
     */
    public static function fromPayload(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        if (blank($data['status'] ?? null)) {
            throw ZapmizerVerificationException::unexpectedResponse('missing confirmation status');
        }

        return new self(
            status: (string) $data['status'],
            number: $data['number'] ?? null,
            from: $data['from'] ?? null,
            attemptsLeft: isset($data['attempts_left']) ? (int) $data['attempts_left'] : null,
            raw: $data,
        );
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isInvalid(): bool
    {
        return $this->status === self::STATUS_INVALID;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isNotFound(): bool
    {
        return $this->status === self::STATUS_NOT_FOUND;
    }
}
