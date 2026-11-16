<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `incoming_call` webhook's data. QR numbers only.
 *
 * - `from`: Who is calling.
 *
 * Read only when `call_id` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class IncomingCall
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $callId,
        public readonly ?string $from,
        public readonly bool $isVideo,
        public readonly bool $isGroup,
        public readonly bool $fromMe,
        public readonly ?Carbon $occurredAt,
        public readonly array $raw = [],
    ) {
    }

    public static function fromData(mixed $data): ?self
    {
        $data = Fields::data($data);
        $callId = $data === null ? null : Fields::identifier($data, 'call_id');

        if ($callId === null) {
            return null;
        }

        return new self(
            callId: $callId,
            from: Fields::string($data, 'from'),
            isVideo: Fields::bool($data, 'is_video'),
            isGroup: Fields::bool($data, 'is_group'),
            fromMe: Fields::bool($data, 'from_me'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
