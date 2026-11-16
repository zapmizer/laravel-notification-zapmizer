<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `message_edited` webhook's data. QR numbers only.
 *
 * - `chatWid`: The chat.
 * - `fromMe`: The message was sent by the connected number.
 * - `body`: The new text.
 * - `previousBody`: The previous text.
 *
 * Read only when `wapp_message_id` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class MessageEdit
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $wappMessageId,
        public readonly ?string $chatWid,
        public readonly bool $fromMe,
        public readonly ?string $body,
        public readonly ?string $previousBody,
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
            body: Fields::string($data, 'body'),
            previousBody: Fields::string($data, 'previous_body'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
