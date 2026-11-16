<?php

namespace NotificationChannels\Zapmizer\Messages;

use Carbon\CarbonImmutable;
use NotificationChannels\Zapmizer\Support\PhoneNumber;

/**
 * Class Message.
 *
 * A WhatsApp message in Zapmizer's message contract (version `2026-10-20`):
 * the `data` of the `message_received` and `message_sent` webhooks and each
 * `message` of `GET /whatsapp-messages`. The same shape for a number by QR
 * and for the official number, and the one shape every listener of the
 * package gets: the `message` event of a webhook in `2024-02-27` and the
 * sync of a Zapmizer before 1.148.17 come in WhatsApp Web's shape and are
 * translated into it (`fromLegacy()`, the port of Zapmizer's own
 * translation).
 *
 * - `id`: WhatsApp's id of the message (`wamid...` on the official number).
 *   It may repeat across chats: deduplicate on `chat` + `id`.
 * - `chat`: the conversation: the contact (`...@c.us`), the group
 *   (`...@g.us`), or `...@lid` when WhatsApp did not reveal the number.
 * - `sender`: who wrote it: the contact, the participant in a group, or the
 *   connected number itself when `fromMe`. `senderName`: their display
 *   name, null when `fromMe`.
 * - `fromMe`: sent by the connected number: through the API, the bot or the
 *   phone. `fromMeOtherDevice`: sent from another device, such as the phone
 *   (always false in a group).
 * - `timestamp`: when WhatsApp says the message was sent, in seconds (UTC);
 *   0 when the payload did not carry one. `sentAt()` is the same instant
 *   (now, without a usable timestamp).
 * - `type`: one of `MessageType`, or a type added on Zapmizer's side later.
 * - `text`: the text, the media's caption, the poll's question or the
 *   option chosen in a `button_reply`/`list_reply`.
 * - `raw`: only on `type: unknown`: the message as WhatsApp delivered it.
 *   Keep it: it is what is left of a type the contract does not cover yet.
 *
 * The number that received the message is not here, because the contract
 * does not carry it: it is the webhook's `Envelope::$wid`, the sync's
 * `SyncedMessages::$wid` and the event's `$connection`.
 *
 * Read only fields: build it with `fromContract()` or `fromLegacy()`.
 */
final class Message
{
    /** The version of the message contract this class reads. */
    public const CONTRACT_VERSION = '2026-10-20';

    /**
     * @param list<string> $mentions
     * @param list<array<array-key, mixed>>|null $contacts Each `{name, vcard}`, as the contract sends it.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $chat,
        public readonly ?string $sender,
        public readonly ?string $senderName,
        public readonly bool $fromMe,
        public readonly bool $fromMeOtherDevice,
        public readonly int $timestamp,
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?QuotedMessage $quoted = null,
        public readonly bool $forwarded = false,
        public readonly bool $viewOnce = false,
        public readonly array $mentions = [],
        public readonly ?MessageMedia $media = null,
        public readonly ?MessageLocation $location = null,
        public readonly ?array $contacts = null,
        public readonly ?MessagePoll $poll = null,
        public readonly ?MessageReply $reply = null,
        public readonly ?LinkPreview $linkPreview = null,
        public readonly mixed $raw = null,
    ) {
    }

    /**
     * Read one message of the contract. Tolerant: an unknown field is
     * ignored, and a missing one (or one of the wrong type) reads as null,
     * false, `[]`, `''` (`chat`), 0 (`timestamp`) or `unknown` (`type`).
     * Null only without a string `id`.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromContract(array $data): ?self
    {
        $id = ContractFields::string($data, 'id');

        if ($id === null) {
            return null;
        }

        $type = ContractFields::string($data, 'type');
        $quoted = ContractFields::object($data, 'quoted');
        $media = ContractFields::object($data, 'media');
        $location = ContractFields::object($data, 'location');
        $contacts = ContractFields::object($data, 'contacts');
        $poll = ContractFields::object($data, 'poll');
        $reply = ContractFields::object($data, 'reply');
        $linkPreview = ContractFields::object($data, 'link_preview');

        return new self(
            id: $id,
            chat: ContractFields::string($data, 'chat') ?? '',
            sender: ContractFields::string($data, 'sender'),
            senderName: ContractFields::string($data, 'sender_name'),
            fromMe: ContractFields::bool($data, 'from_me') ?? false,
            fromMeOtherDevice: ContractFields::bool($data, 'from_me_other_device') ?? false,
            timestamp: ContractFields::int($data, 'timestamp') ?? 0,
            type: $type === null || $type === '' ? MessageType::UNKNOWN : $type,
            text: ContractFields::string($data, 'text'),
            quoted: $quoted === null ? null : QuotedMessage::fromContract($quoted),
            forwarded: ContractFields::bool($data, 'forwarded') ?? false,
            viewOnce: ContractFields::bool($data, 'view_once') ?? false,
            mentions: ContractFields::strings($data, 'mentions'),
            media: $media === null ? null : MessageMedia::fromContract($media),
            location: $location === null ? null : MessageLocation::fromContract($location),
            contacts: $contacts === null ? null : array_values(array_filter($contacts, 'is_array')),
            poll: $poll === null ? null : MessagePoll::fromContract($poll),
            reply: $reply === null ? null : MessageReply::fromContract($reply),
            linkPreview: $linkPreview === null ? null : LinkPreview::fromContract($linkPreview),
            raw: $data['raw'] ?? null,
        );
    }

    /**
     * Translate a message in WhatsApp Web's shape (whatsapp-web.js): the
     * `data[0]` of the `message` webhook (version `2024-02-27`) and each
     * `message` of `GET /whatsapp-messages` without the contract. Gives the
     * message Zapmizer would have delivered in the contract; never null (what
     * cannot be read comes as `unknown`, with the original in `raw`).
     *
     * It translates WhatsApp's notices too (a member joining a group, a
     * deleted message...), which Zapmizer leaves out of the contract: see
     * `LegacyMessageTranslator::forEvent()` and `forSync()`, which apply
     * Zapmizer's rules.
     *
     * @param array<array-key, mixed> $message
     */
    public static function fromLegacy(array $message): self
    {
        return LegacyMessageTranslator::translate($message);
    }

    /**
     * `timestamp` as a date, in the application's timezone. Now when the
     * payload carried no usable timestamp (missing, 0, negative or beyond
     * year 6000), as the package always did.
     */
    public function sentAt(): CarbonImmutable
    {
        $timezone = date_default_timezone_get();

        if ($this->timestamp <= 0 || $this->timestamp >= 2 ** 37) {
            return CarbonImmutable::now($timezone);
        }

        return CarbonImmutable::createFromTimestamp($this->timestamp, $timezone);
    }

    /**
     * The contact's number (digits only) in a chat with a contact
     * (`...@c.us`). Null in a group, a broadcast, a channel or a `@lid`
     * chat, where WhatsApp did not reveal the number.
     */
    public function chatPhone(): ?string
    {
        return self::phoneOf($this->chat);
    }

    /**
     * The number (digits only) of who wrote the message, when it is a
     * `...@c.us`; null for a `@lid` sender or without one. When `fromMe`,
     * the connected number.
     */
    public function senderPhone(): ?string
    {
        return self::phoneOf($this->sender);
    }

    public function isGroup(): bool
    {
        return PhoneNumber::isGroupWid($this->chat);
    }

    /**
     * A status update (`status@broadcast`) or a message of a broadcast list.
     */
    public function isBroadcast(): bool
    {
        return PhoneNumber::isBroadcastWid($this->chat);
    }

    public function hasMedia(): bool
    {
        return $this->media !== null;
    }

    private static function phoneOf(?string $wid): ?string
    {
        if ($wid === null || !str_ends_with($wid, '@c.us')) {
            return null;
        }

        $phone = PhoneNumber::fromWid($wid);

        return $phone === '' ? null : $phone;
    }
}
