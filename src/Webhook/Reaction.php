<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `message_reacted` webhook's data: someone reacted to a message, or took
 * the reaction back (`removed`, with an empty `emoji`). QR numbers only.
 *
 * - `wappMessageId`: The message that got the reaction.
 * - `chatWid`: The chat.
 * - `from`: Who reacted.
 * - `emoji`: Empty when the reaction was removed.
 *
 * Read only when `wapp_message_id` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class Reaction
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $wappMessageId,
        public readonly ?string $chatWid,
        public readonly ?string $from,
        public readonly ?string $emoji,
        public readonly bool $removed,
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
            from: Fields::string($data, 'from'),
            emoji: Fields::string($data, 'emoji'),
            removed: Fields::bool($data, 'removed'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
