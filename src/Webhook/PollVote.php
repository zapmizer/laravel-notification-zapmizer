<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `poll_vote` webhook's data: someone voted on a poll sent by the
 * number. QR numbers only. `selectedOptions` are the options marked now.
 *
 * - `wappMessageId`: The poll.
 * - `chatWid`: The chat.
 * - `voter`: Who voted.
 *
 * Read only when `wapp_message_id` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class PollVote
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $wappMessageId,
        public readonly ?string $chatWid,
        public readonly ?string $voter,
        /** @var array<int, string> */
        public readonly array $selectedOptions,
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
            voter: Fields::string($data, 'voter'),
            selectedOptions: Fields::strings($data, 'selected_options'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
