<?php

namespace NotificationChannels\Zapmizer\Connect;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\InboundMessage;

/**
 * Class SyncedMessages.
 *
 * One page of GET /whatsapp-messages: every message of the number stored
 * after the cursor — received and sent, the ones that came in the sync after
 * a reconnect included — each
 * translated the way the `message` webhook is (`InboundMessage::fromMessage()`,
 * wwebjs and Rust engines alike).
 *
 * Zapmizer neither filters nor deduplicates: the same message may come back
 * here more than once and also through the webhook. Deduplicate on
 * `$message->id`, and skip `fromMe` when you only want what was received.
 *
 * Keep `cursor` and pass it on the next call; while `hasMore`, call again.
 */
final class SyncedMessages
{
    /**
     * @param list<InboundMessage> $messages
     * @param list<array<string, mixed>> $payloads The raw Message objects, in the order of `$messages`.
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $cursor,
        public readonly bool $hasMore,
        public readonly array $payloads = [],
    ) {
    }

    /**
     * Entries that do not translate (no id) are dropped, not fatal: one bad
     * row must not stall the cursor for the whole number.
     *
     * @param array<string, mixed> $body
     *
     * @throws ZapmizerConnectException
     */
    public static function fromResponse(array $body, ?string $cursor): self
    {
        // The message contract (`api-version` 2026-10-20 and later) carries a
        // `version` and a shape this class cannot translate: reading it would
        // drop every entry and still move the cursor past them.
        if (array_key_exists('version', $body)) {
            throw ZapmizerConnectException::unexpectedResponse('GET /whatsapp-messages answered in the message contract (version ' . (is_scalar($body['version']) ? $body['version'] : '?') . '), which this version does not read');
        }

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

        $next = $body['next_cursor'] ?? null;

        return new self(
            messages: $messages,
            cursor: is_string($next) && $next !== '' ? $next : $cursor,
            hasMore: (bool) ($body['has_more'] ?? false),
            payloads: $payloads,
        );
    }
}
