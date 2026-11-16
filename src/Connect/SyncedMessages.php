<?php

namespace NotificationChannels\Zapmizer\Connect;

use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Messages\LegacyMessageTranslator;
use NotificationChannels\Zapmizer\Messages\Message;

/**
 * Class SyncedMessages.
 *
 * One page of GET /whatsapp-messages: every message of the number stored
 * after the cursor — received and sent, the ones that came in the sync after
 * a reconnect included — each a `Message`, the same the webhook gives.
 *
 * - A Zapmizer from 1.148.17 on answers in the message contract
 *   (`version` `2026-10-20`): each message is read as it came, on a number by
 *   QR and on the official one. WhatsApp's notices that are not messages
 *   (someone joining a group...) are left out by Zapmizer, but the cursor
 *   passes them: `messages` may be empty with `hasMore` true — call again.
 *   A deleted or edited message, or one not yet decrypted, comes as
 *   `unknown`, with the original in `raw`.
 * - An older Zapmizer answers in WhatsApp Web's shape (numbers by QR only):
 *   each message is translated the way Zapmizer translates it, with the same
 *   rule for notices (`LegacyMessageTranslator::forSync()`).
 *
 * `wid` is the number, as Zapmizer sends it (`{phone_number}@c.us`);
 * `version` the contract's version, null from a Zapmizer before 1.148.17.
 * `payloads` are the messages as they came (contract or WhatsApp Web), in the
 * order of `messages`.
 *
 * Zapmizer neither filters nor deduplicates: the same message may come back
 * here more than once and also through the webhook. Deduplicate on
 * `$message->chat` + `$message->id`, and skip `fromMe` when you only want
 * what was received.
 *
 * Keep `cursor` and pass it on the next call; while `hasMore`, call again.
 */
final class SyncedMessages
{
    /**
     * @param list<Message> $messages
     * @param list<array<array-key, mixed>> $payloads The messages as they came, in the order of `$messages`.
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $cursor,
        public readonly bool $hasMore,
        public readonly array $payloads = [],
        public readonly ?string $wid = null,
        public readonly ?string $version = null,
    ) {
    }

    /**
     * A version of the contract this package does not know is refused
     * (`unexpectedResponse`): reading it could drop messages while the
     * cursor moves past them, and the page can be asked again once the
     * package is updated. An entry that is not a message is dropped with a
     * warning, not fatal: one bad row must not stall the cursor for the
     * whole number.
     *
     * @param array<string, mixed> $body
     *
     * @throws ZapmizerConnectException
     */
    public static function fromResponse(array $body, ?string $cursor): self
    {
        $contract = array_key_exists('version', $body);
        $version = $body['version'] ?? null;

        if ($contract && $version !== Message::CONTRACT_VERSION) {
            throw ZapmizerConnectException::unexpectedResponse('GET /whatsapp-messages answered in version ' . (is_scalar($version) ? $version : '?') . ' of the message contract, which this version of the package does not know');
        }

        $messages = [];
        $payloads = [];
        $unreadable = 0;

        foreach ((array) ($body['data'] ?? []) as $entry) {
            $raw = is_array($entry) ? ($entry['message'] ?? null) : null;

            if (!is_array($raw)) {
                $unreadable++;

                continue;
            }

            if ($contract) {
                $message = Message::fromContract($raw);
                $unreadable += $message === null ? 1 : 0;
            } else {
                // Null only for a notice Zapmizer leaves out of the sync.
                $message = LegacyMessageTranslator::forSync($raw);
            }

            if ($message === null) {
                continue;
            }

            $messages[] = $message;
            $payloads[] = $raw;
        }

        $next = $body['next_cursor'] ?? null;
        $page = new self(
            messages: $messages,
            cursor: is_string($next) && $next !== '' ? $next : $cursor,
            hasMore: (bool) ($body['has_more'] ?? false),
            payloads: $payloads,
            wid: is_string($body['wid'] ?? null) && $body['wid'] !== '' ? $body['wid'] : null,
            version: $contract ? Message::CONTRACT_VERSION : null,
        );

        if ($unreadable > 0) {
            Log::warning('zapmizer: GET /whatsapp-messages returned entries that are not a message; they were skipped.', array_filter([
                'skipped' => $unreadable,
                'version' => $page->version,
                'wid' => $page->wid,
                'cursor' => $page->cursor,
            ], fn ($value) => $value !== null));
        }

        return $page;
    }
}
