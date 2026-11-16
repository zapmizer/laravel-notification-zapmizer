<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * The `type` values of the message contract (`2026-10-20`), as Zapmizer's
 * `openapi.json` lists them for `message_received`/`message_sent`. A type
 * Zapmizer does not cover yet comes as `unknown`, with the original in
 * `Message::$raw`. `Message::$type` is a plain string: a type added on
 * Zapmizer's side reaches the application before a release of the package.
 */
final class MessageType
{
    public const TEXT = 'text';

    public const IMAGE = 'image';

    public const VIDEO = 'video';

    public const AUDIO = 'audio';

    /** A voice note (`ptt` in WhatsApp Web). */
    public const VOICE = 'voice';

    public const DOCUMENT = 'document';

    public const STICKER = 'sticker';

    public const LOCATION = 'location';

    /** One or more shared contacts (`vcard`/`multi_vcard` in WhatsApp Web). */
    public const CONTACT = 'contact';

    public const POLL = 'poll';

    /** The choice made on a message with buttons: `Message::$reply`. */
    public const BUTTON_REPLY = 'button_reply';

    /** The row chosen on a list message: `Message::$reply`. */
    public const LIST_REPLY = 'list_reply';

    public const UNKNOWN = 'unknown';

    /** The types whose message carries a file (`Message::$media`). */
    public const MEDIA = [self::IMAGE, self::VIDEO, self::AUDIO, self::VOICE, self::DOCUMENT, self::STICKER];

    public const ALL = [
        self::TEXT,
        self::IMAGE,
        self::VIDEO,
        self::AUDIO,
        self::VOICE,
        self::DOCUMENT,
        self::STICKER,
        self::LOCATION,
        self::CONTACT,
        self::POLL,
        self::BUTTON_REPLY,
        self::LIST_REPLY,
        self::UNKNOWN,
    ];

    private function __construct()
    {
    }
}
