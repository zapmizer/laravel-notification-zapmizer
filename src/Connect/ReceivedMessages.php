<?php

namespace NotificationChannels\Zapmizer\Connect;

use NotificationChannels\Zapmizer\InboundMessage;

/**
 * Class ReceivedMessages.
 *
 * One page of GET /whatsapp-messages: what the number received, in the order
 * Zapmizer stored it, each message translated the same way the `message`
 * webhook is (`InboundMessage::fromMessage()`, wwebjs and Rust engines alike).
 *
 * It is the way back to what the webhook did not deliver — the app was down
 * past Zapmizer's 3 attempts, or the messages arrived in the sync after the
 * number reconnected. Keep `nextAfter` and pass it as `$after` on the next
 * call; while `hasMore`, call again. The same message may come here and
 * through the webhook: deduplicate on `$message->id`.
 */
final class ReceivedMessages
{
    /**
     * @param list<InboundMessage> $messages
     * @param list<array<string, mixed>> $payloads The raw Message objects, in the order of `$messages`.
     */
    public function __construct(
        public readonly array $messages,
        public readonly int $nextAfter,
        public readonly bool $hasMore,
        public readonly array $payloads = [],
    ) {
    }

    /**
     * Entries that do not translate (no id) are dropped, not fatal: one bad
     * row must not stall the cursor for the whole number.
     *
     * @param array<string, mixed> $body
     */
    public static function fromResponse(array $body, int $after): self
    {
        $wid = is_string($body['wid'] ?? null) && $body['wid'] !== '' ? $body['wid'] : null;
        $messages = [];
        $payloads = [];

        foreach ((array) ($body['data'] ?? []) as $entry) {
            $raw = is_array($entry) ? ($entry['message'] ?? null) : null;

            if (!is_array($raw) || ($message = InboundMessage::fromMessage($raw, $wid)) === null) {
                continue;
            }

            $messages[] = $message;
            $payloads[] = $raw;
        }

        return new self(
            messages: $messages,
            nextAfter: is_numeric($body['next_after'] ?? null) ? max($after, (int) $body['next_after']) : $after,
            hasMore: (bool) ($body['has_more'] ?? false),
            payloads: $payloads,
        );
    }
}
