<?php

namespace NotificationChannels\Zapmizer\Messages;

use RuntimeException;
use Throwable;

/**
 * Class LegacyMessageTranslator.
 *
 * WhatsApp Web's message (the whatsapp-web.js object) into Zapmizer's
 * message contract. A port of Zapmizer's own translation, so a message reads
 * the same whether it came as `message` (webhook `2024-02-27`) or as
 * `message_received`/`message_sent` (`2026-10-20`):
 *
 * - `bot-v2/src/messaging/from-wwebjs.ts` (WhatsApp Web → Zapmizer's
 *   message) and `toPartnerMessage()` of `bot-v2/src/messaging/partner.ts`
 *   (→ the contract);
 * - the notices of `WHATSAPP_WEB_NOTICES` (same file): WhatsApp events that
 *   come as messages but are not one. The webhook leaves every notice out
 *   (`emitsMessageEvent`, here `forEvent()`); the sync leaves out only the
 *   `system` ones and gives the others as `unknown` (`syncsMessage`, here
 *   `forSync()`).
 *
 * The tests hold it to Zapmizer's own fixtures
 * (`tests/fixtures/message-contract/wwebjs`). Whatever the input, it never
 * throws: what cannot be read comes as `unknown`, with the original in `raw`.
 */
final class LegacyMessageTranslator
{
    public const NOTICE_SYSTEM = 'system';

    public const NOTICE_PENDING = 'pending';

    public const NOTICE_CHANGE = 'change';

    /**
     * WhatsApp Web's message types that are not a message, by notice kind
     * (`WHATSAPP_WEB_NOTICES` of `partner.ts`).
     */
    public const NOTICES = [
        'call_log' => self::NOTICE_SYSTEM,
        'voice_call' => self::NOTICE_SYSTEM,
        'gp2' => self::NOTICE_SYSTEM,
        'e2e_notification' => self::NOTICE_SYSTEM,
        'notification_template' => self::NOTICE_SYSTEM,
        'protocol' => self::NOTICE_SYSTEM,
        'message_history_notice' => self::NOTICE_SYSTEM,
        'pinned_message' => self::NOTICE_SYSTEM,
        'group-history' => self::NOTICE_SYSTEM,
        'biz_content_placeholder' => self::NOTICE_SYSTEM,
        'ciphertext' => self::NOTICE_PENDING,
        'revoked' => self::NOTICE_CHANGE,
        'message_edit_encrypted' => self::NOTICE_CHANGE,
    ];

    /** `NATIVE_TYPES` of `bot-v2/src/messaging/message.ts`. */
    private const NATIVE_TYPES = [
        'chat' => MessageType::TEXT,
        'interactive' => MessageType::TEXT,
        'list' => MessageType::TEXT,
        'buttons' => MessageType::TEXT,
        'template' => MessageType::TEXT,
        'image' => MessageType::IMAGE,
        'video' => MessageType::VIDEO,
        'ptv' => MessageType::VIDEO,
        'audio' => MessageType::AUDIO,
        'ptt' => MessageType::VOICE,
        'document' => MessageType::DOCUMENT,
        'sticker' => MessageType::STICKER,
        'location' => MessageType::LOCATION,
        'vcard' => MessageType::CONTACT,
        'multi_vcard' => MessageType::CONTACT,
        'poll_creation' => MessageType::POLL,
        'buttons_response' => MessageType::BUTTON_REPLY,
        'template_button_reply' => MessageType::BUTTON_REPLY,
        'interactive_response' => MessageType::BUTTON_REPLY,
        'list_response' => MessageType::LIST_REPLY,
    ];

    private function __construct()
    {
    }

    /**
     * The message, notices included.
     *
     * @param array<array-key, mixed> $message
     */
    public static function translate(array $message): Message
    {
        return self::messageOf(self::read($message)[0]);
    }

    /**
     * The message as the webhook would deliver it, or null for a notice
     * (Zapmizer's `emitsMessageEvent`).
     *
     * @param array<array-key, mixed> $message
     */
    public static function forEvent(array $message): ?Message
    {
        [$contract, $nativeType] = self::read($message);

        return isset(self::NOTICES[$nativeType]) ? null : self::messageOf($contract);
    }

    /**
     * The message as the sync would return it, or null for a `system`
     * notice (Zapmizer's `syncsMessage`): a pending or changed message comes
     * as `unknown`, with the original in `raw`.
     *
     * @param array<array-key, mixed> $message
     */
    public static function forSync(array $message): ?Message
    {
        [$contract, $nativeType] = self::read($message);

        return (self::NOTICES[$nativeType] ?? null) === self::NOTICE_SYSTEM ? null : self::messageOf($contract);
    }

    /**
     * The notice kind of the message (`system`, `pending`, `change`), or null
     * when it is a message.
     *
     * @param array<array-key, mixed> $message
     */
    public static function notice(array $message): ?string
    {
        return self::NOTICES[self::read($message)[1]] ?? null;
    }

    /**
     * The message in the contract's own shape (snake_case, as
     * `message_received.data`), as Zapmizer would have written it.
     *
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public static function toContract(array $message): array
    {
        return self::read($message)[0];
    }

    /**
     * @param array<string, mixed> $contract
     */
    private static function messageOf(array $contract): Message
    {
        return Message::fromContract($contract)
            ?? throw new RuntimeException('The translated message has no id.');
    }

    /**
     * `fromWwebjs()`: `readSafely()` around `readWwebjs()`.
     *
     * @param array<array-key, mixed> $raw
     * @return array{0: array<string, mixed>, 1: string} The contract and WhatsApp Web's own type.
     */
    private static function read(array $raw): array
    {
        try {
            return self::readWwebjs($raw);
        } catch (Throwable) {
            return [self::unknown($raw, self::identityOf($raw)), MessageType::UNKNOWN];
        }
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function readWwebjs(array $message): array
    {
        $data = self::dataOf($message);
        $id = self::nonEmpty(self::at($message, 'id', 'id')) ?? self::nonEmpty(self::at($data, 'id', 'id'));
        $nativeType = self::nonEmpty($message['type'] ?? null) ?? self::nonEmpty($data['type'] ?? null);

        if ($id === null || $nativeType === null) {
            return [self::unknown($message, self::identityOf($message)), MessageType::UNKNOWN];
        }

        $fromMe = self::fromMeOf($message, $data);
        $from = self::widOf($message['from'] ?? $data['from'] ?? null);
        $to = self::widOf($message['to'] ?? $data['to'] ?? null);
        $author = self::widOf($message['author'] ?? $data['author'] ?? null);
        $chat = ($fromMe ? $to : $from) ?? self::widOf(self::at($message, 'id', 'remote')) ?? '';
        $group = str_ends_with($chat, '@g.us') || str_ends_with($chat, '@broadcast');
        $type = self::NATIVE_TYPES[$nativeType] ?? MessageType::UNKNOWN;
        $reply = self::replyOf($type, $message, $data);
        $media = in_array($type, MessageType::MEDIA, true);

        $contract = [
            'id' => $id,
            'chat' => $chat,
            'sender' => $fromMe || !$group ? $from : $author,
            'sender_name' => $fromMe ? null : self::nonEmpty($data['notifyName'] ?? null),
            'from_me' => $fromMe,
            'from_me_other_device' => $fromMe && !$group && self::truthy($author),
            'timestamp' => self::numberOrNull($message['timestamp'] ?? $data['t'] ?? null) ?? 0,
            'type' => $type,
            'text' => $reply !== null ? self::nonEmpty($reply['label']) : self::textOf($type, $message, $data),
            'quoted' => self::quoteOf($data),
            'forwarded' => self::truthy($message['isForwarded'] ?? $data['isForwarded'] ?? null),
            'view_once' => self::truthy($data['isViewOnce'] ?? $message['isViewOnce'] ?? null),
            'mentions' => self::widsOf($message['mentionedIds'] ?? $data['mentionedJidList'] ?? null),
            'media' => $media ? self::mediaOf($message, $data) : null,
            'location' => $type === MessageType::LOCATION ? self::locationOf($message['location'] ?? null, $data) : null,
            'contacts' => $type === MessageType::CONTACT ? self::contactsOf($message, $data) : null,
            'poll' => $type === MessageType::POLL ? self::pollOf($message, $data) : null,
            'reply' => $reply,
            'link_preview' => self::linkPreviewOf($data),
        ];

        if ($type === MessageType::UNKNOWN) {
            $contract['raw'] = $message;
        }

        return [$contract, $nativeType];
    }

    /**
     * `unknownMessage()` through `toPartnerMessage()`.
     *
     * @param array<array-key, mixed> $raw
     * @param array{id?: string, chat?: string, from_me?: bool, timestamp?: int|float} $known
     * @return array<string, mixed>
     */
    private static function unknown(array $raw, array $known): array
    {
        return [
            'id' => $known['id'] ?? '',
            'chat' => $known['chat'] ?? '',
            'sender' => null,
            'sender_name' => null,
            'from_me' => $known['from_me'] ?? false,
            'from_me_other_device' => false,
            'timestamp' => $known['timestamp'] ?? 0,
            'type' => MessageType::UNKNOWN,
            'text' => null,
            'quoted' => null,
            'forwarded' => false,
            'view_once' => false,
            'mentions' => [],
            'media' => null,
            'location' => null,
            'contacts' => null,
            'poll' => null,
            'reply' => null,
            'link_preview' => null,
            'raw' => $raw,
        ];
    }

    /**
     * `identityOf()`: what is known of a message that cannot be read, so a
     * message the number sent still reads as sent.
     *
     * @param array<array-key, mixed> $message
     * @return array{id: string, chat: string, from_me: bool, timestamp: int|float}
     */
    private static function identityOf(array $message): array
    {
        try {
            $data = self::dataOf($message);
            $fromMe = self::fromMeOf($message, $data);
            $chat = self::widOf($fromMe ? ($message['to'] ?? $data['to'] ?? null) : ($message['from'] ?? $data['from'] ?? null))
                ?? self::widOf(self::at($message, 'id', 'remote') ?? self::at($data, 'id', 'remote'));

            return [
                'id' => self::nonEmpty(self::at($message, 'id', 'id')) ?? self::nonEmpty(self::at($data, 'id', 'id')) ?? '',
                'chat' => $chat ?? '',
                'from_me' => $fromMe,
                'timestamp' => self::numberOrNull($message['timestamp'] ?? $data['t'] ?? null) ?? 0,
            ];
        } catch (Throwable) {
            return ['id' => '', 'chat' => '', 'from_me' => false, 'timestamp' => 0];
        }
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<array-key, mixed>
     */
    private static function dataOf(array $message): array
    {
        return is_array($message['_data'] ?? null) ? $message['_data'] : [];
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<array-key, mixed> $data
     */
    private static function fromMeOf(array $message, array $data): bool
    {
        return self::truthy($message['fromMe'] ?? self::at($message, 'id', 'fromMe') ?? self::at($data, 'id', 'fromMe'));
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<array-key, mixed> $data
     */
    private static function textOf(string $type, array $message, array $data): ?string
    {
        if ($type === MessageType::POLL) {
            return self::nonEmpty($message['pollName'] ?? $data['pollName'] ?? null) ?? self::nonEmpty($message['body'] ?? null);
        }

        if (in_array($type, [MessageType::CONTACT, MessageType::LOCATION, MessageType::UNKNOWN], true)) {
            return null;
        }

        if (in_array($type, MessageType::MEDIA, true)) {
            return self::captionOf($type, $message, $data);
        }

        return self::nonEmpty($message['body'] ?? $data['body'] ?? null)
            ?? self::nonEmpty($message['caption'] ?? null)
            ?? self::nonEmpty(self::at($data, 'list', 'description'));
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<array-key, mixed> $data
     */
    private static function captionOf(string $type, array $message, array $data): ?string
    {
        $caption = self::nonEmpty($message['caption'] ?? null)
            ?? self::nonEmpty($data['caption'] ?? null)
            ?? (self::truthy($message['hasMedia'] ?? null) ? self::nonEmpty($message['body'] ?? null) : null);

        if ($type === MessageType::DOCUMENT && $caption !== null && $caption === ($data['filename'] ?? null) && ($data['isCaptionByUser'] ?? null) !== true) {
            return null;
        }

        return $caption;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{id: string, sender: ?string, type: ?string, text: ?string}|null
     */
    private static function quoteOf(array $data): ?array
    {
        $quoted = $data['quotedMsg'] ?? null;
        $id = self::nonEmpty($data['quotedStanzaID'] ?? null);

        if (!self::truthy($quoted) || $id === null) {
            return null;
        }

        $quoted = is_array($quoted) ? $quoted : [];
        $nativeType = self::nonEmpty($quoted['type'] ?? null);
        $type = $nativeType === null ? null : self::NATIVE_TYPES[$nativeType] ?? MessageType::UNKNOWN;

        if ($type === MessageType::CONTACT) {
            // Not in the contract, but read all the same: a card it cannot
            // read makes the whole message unknown, as on Zapmizer.
            self::quotedContacts($quoted);
        }

        return [
            'id' => $id,
            'sender' => self::widOf($data['quotedParticipant'] ?? null),
            'type' => $type,
            'text' => self::quotedText($type, $quoted),
        ];
    }

    /**
     * @param array<array-key, mixed> $quoted
     */
    private static function quotedText(?string $type, array $quoted): ?string
    {
        if ($type === MessageType::CONTACT || $type === MessageType::LOCATION) {
            return null;
        }

        if ($type !== null && in_array($type, MessageType::MEDIA, true)) {
            return self::nonEmpty($quoted['caption'] ?? null);
        }

        if ($type === MessageType::POLL) {
            return self::nonEmpty($quoted['pollName'] ?? null) ?? self::nonEmpty($quoted['body'] ?? null);
        }

        return self::nonEmpty($quoted['body'] ?? null)
            ?? self::nonEmpty($quoted['caption'] ?? null)
            ?? self::nonEmpty(self::at($quoted, 'list', 'description'));
    }

    /**
     * @param array<array-key, mixed> $quoted
     * @return list<array{name: ?string, vcard: string}>
     */
    private static function quotedContacts(array $quoted): array
    {
        $cards = $quoted['vcardList'] ?? null;

        if (self::isList($cards) && $cards !== []) {
            return array_map(fn ($card) => self::cardOf($card), $cards);
        }

        $body = self::nonEmpty($quoted['body'] ?? null);

        return $body === null ? [] : [self::contactOf($body, self::nonEmpty($quoted['vcardFormattedName'] ?? null))];
    }

    /**
     * `mediaOf()`, with the fields the contract keeps.
     *
     * @param array<array-key, mixed> $message
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function mediaOf(array $message, array $data): array
    {
        return [
            'mime' => self::nonEmpty($data['mimetype'] ?? null),
            'filename' => self::nonEmpty($data['filename'] ?? null),
            'size' => self::numberOrNull($data['size'] ?? null),
            'duration' => self::numberOrNull($data['duration'] ?? $message['duration'] ?? null),
            'width' => self::numberOrNull($data['width'] ?? null),
            'height' => self::numberOrNull($data['height'] ?? null),
            'page_count' => self::numberOrNull($data['pageCount'] ?? null),
            'is_gif' => self::booleanOrNull($data['isGif'] ?? $message['isGif'] ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function locationOf(mixed $location, array $data): array
    {
        if (is_array($location)) {
            return [
                'latitude' => self::numberOrNull($location['latitude'] ?? null) ?? 0,
                'longitude' => self::numberOrNull($location['longitude'] ?? null) ?? 0,
                'name' => self::nonEmpty($location['name'] ?? null),
                'address' => self::nonEmpty($location['address'] ?? null),
                'url' => self::nonEmpty($location['url'] ?? null),
            ];
        }

        $loc = $data['loc'] ?? null;
        $parts = is_string($loc) ? explode("\n", $loc) : [];

        return [
            'latitude' => self::numberOrNull($data['lat'] ?? null) ?? 0,
            'longitude' => self::numberOrNull($data['lng'] ?? null) ?? 0,
            'name' => self::nonEmpty($parts[0] ?? null),
            'address' => self::nonEmpty($parts[1] ?? null),
            'url' => self::nonEmpty($data['clientUrl'] ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<array-key, mixed> $data
     * @return list<array{name: ?string, vcard: string}>
     */
    private static function contactsOf(array $message, array $data): array
    {
        $list = $data['vcardList'] ?? null;

        if (self::isList($list) && $list !== []) {
            return array_map(fn ($card) => self::cardOf($card), $list);
        }

        $vcards = self::isList($message['vCards'] ?? null) && $message['vCards'] !== [] ? $message['vCards'] : [$message['body'] ?? null];
        $cards = array_values(array_filter($vcards, fn ($vcard) => self::nonEmpty($vcard) !== null));
        $name = count($cards) === 1 ? self::nonEmpty($data['vcardFormattedName'] ?? null) : null;

        return array_map(fn (string $vcard) => self::contactOf($vcard, $name), $cards);
    }

    /**
     * One entry of `vcardList`. A null entry makes the whole message
     * unknown, as `card.vcard` throws on Zapmizer.
     *
     * @return array{name: ?string, vcard: string}
     */
    private static function cardOf(mixed $card): array
    {
        if ($card === null) {
            throw new RuntimeException('A vcardList entry is null.');
        }

        $vcard = is_array($card) ? $card['vcard'] ?? null : null;
        $displayName = is_array($card) ? $card['displayName'] ?? null : null;

        return self::contactOf(self::jsString($vcard ?? ''), self::nonEmpty($displayName));
    }

    /**
     * `contactOf()` of `bot-v2/src/messaging/vcard.ts`.
     *
     * @return array{name: ?string, vcard: string}
     */
    private static function contactOf(string $vcard, ?string $displayName): array
    {
        return ['name' => self::nonEmpty($displayName) ?? self::nameOf($vcard), 'vcard' => $vcard];
    }

    /**
     * The `FN` of a vCard.
     */
    private static function nameOf(string $vcard): ?string
    {
        foreach (preg_split('/\r?\n/', $vcard) ?: [] as $line) {
            if (preg_match('/^FN[:;]/i', $line) === 1) {
                $colon = strpos($line, ':');
                $name = self::jsTrim(substr($line, $colon === false ? 0 : $colon + 1));

                return $name === '' ? null : $name;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<array-key, mixed> $data
     * @return array{name: string, options: list<string>, multiple: bool}
     */
    private static function pollOf(array $message, array $data): array
    {
        $options = $message['pollOptions'] ?? $data['pollOptions'] ?? [];

        if (!self::isList($options)) {
            throw new RuntimeException('pollOptions is not a list.');
        }

        $multiple = $message['allowMultipleAnswers'] ?? null;

        return [
            'name' => self::nonEmpty($message['pollName'] ?? $data['pollName'] ?? null) ?? '',
            'options' => array_map(
                fn ($option) => is_array($option) ? self::jsString($option['name'] ?? '') : self::jsString($option),
                $options,
            ),
            'multiple' => is_bool($multiple) ? $multiple : !self::truthy($data['pollSelectableOptionsCount'] ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<array-key, mixed> $data
     * @return array{id: ?string, label: string, description: ?string}|null
     */
    private static function replyOf(string $type, array $message, array $data): ?array
    {
        if ($type === MessageType::BUTTON_REPLY) {
            return [
                'id' => self::nonEmpty($message['selectedButtonId'] ?? $data['selectedButtonId'] ?? $data['selectedId'] ?? null),
                'label' => self::nonEmpty($message['body'] ?? $data['body'] ?? null) ?? '',
                'description' => null,
            ];
        }

        if ($type === MessageType::LIST_REPLY) {
            $response = $data['listResponse'] ?? [];
            $response = is_array($response) ? $response : [];
            $firstLine = explode("\n", self::jsString($message['body'] ?? $data['body'] ?? ''))[0];

            return [
                'id' => self::nonEmpty($message['selectedRowId'] ?? self::at($response, 'singleSelectReply', 'selectedRowId')),
                'label' => self::nonEmpty($response['title'] ?? null) ?? $firstLine,
                'description' => self::nonEmpty($response['description'] ?? null),
            ];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{url: string, title: ?string, description: ?string}|null
     */
    private static function linkPreviewOf(array $data): ?array
    {
        $matched = self::nonEmpty($data['matchedText'] ?? null);

        if (($data['subtype'] ?? null) !== 'url' || $matched === null) {
            return null;
        }

        return [
            'url' => self::nonEmpty($data['canonicalUrl'] ?? null) ?? $matched,
            'title' => self::nonEmpty($data['title'] ?? null),
            'description' => self::nonEmpty($data['description'] ?? null),
        ];
    }

    /**
     * A wid as a string: as it came, or `_serialized`, or `user@server` of
     * whatsapp-web.js' wid object.
     */
    private static function widOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return self::nonEmpty($value);
        }

        if (!is_array($value)) {
            return null;
        }

        $serialized = self::nonEmpty($value['_serialized'] ?? null);

        if ($serialized !== null) {
            return $serialized;
        }

        $user = $value['user'] ?? null;
        $server = $value['server'] ?? null;

        return self::truthy($user) && self::truthy($server) ? self::jsString($user) . '@' . self::jsString($server) : null;
    }

    /**
     * @return list<string>
     */
    private static function widsOf(mixed $values): array
    {
        if (!self::isList($values)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($value) => self::widOf($value), $values), fn ($wid) => $wid !== null));
    }

    /**
     * `value?.a?.b`: null when a step is not an array.
     *
     * @param array<array-key, mixed> $value
     */
    private static function at(array $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * JavaScript's `Boolean(value)`.
     */
    private static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_float($value) => $value != 0 && !is_nan($value),
            is_string($value) => $value !== '',
            default => true,
        };
    }

    /**
     * `numberOrNull()` of `bot-v2/src/messaging/text.ts`. A whole number
     * comes back as an int, as it would in Zapmizer's JSON.
     */
    private static function numberOrNull(mixed $value): int|float|null
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || !is_numeric($value)) {
                return null;
            }

            $value += 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (!is_float($value) || !is_finite($value)) {
            return null;
        }

        return floor($value) === $value && abs($value) <= PHP_INT_MAX ? (int) $value : $value;
    }

    private static function booleanOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * JavaScript's `String(value)` for what JSON can carry.
     */
    private static function jsString(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => (string) (self::numberOrNull($value) ?? $value),
            self::isList($value) => implode(',', array_map(fn ($item) => $item === null ? '' : self::jsString($item), $value)),
            default => '[object Object]',
        };
    }

    private static function jsTrim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}\x{A0}]+|[\s\x{FEFF}\x{A0}]+$/u', '', $value);
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }
}
