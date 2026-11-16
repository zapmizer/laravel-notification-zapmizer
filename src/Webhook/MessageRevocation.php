<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `message_revoked` webhook's data: a message was deleted. QR numbers
 * only. `scope` is `everyone` (deleted for everyone) or `me` (only for the
 * connected number).
 *
 * - `chatWid`: The chat.
 * - `scope`: `everyone` or `me`.
 *
 * Read only when `wapp_message_id` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class MessageRevocation
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $wappMessageId,
        public readonly ?string $chatWid,
        public readonly bool $fromMe,
        public readonly ?string $scope,
        public readonly ?Carbon $occurredAt,
        public readonly array $raw = [],
    ) {
    }

    public static function fromData(mixed $data): ?self
    {
        $data = Fields::data($data);
        $wappMessageId = $data === null ? null : Fields::identifier($data, 'wapp_message_id');

        if ($wappMessageId === null) {
            return null;
        }

        return new self(
            wappMessageId: $wappMessageId,
            chatWid: Fields::string($data, 'chat_wid'),
            fromMe: Fields::bool($data, 'from_me'),
            scope: Fields::string($data, 'scope'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
