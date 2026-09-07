<?php

namespace NotificationChannels\Zapmizer;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use NotificationChannels\Zapmizer\Support\PhoneNumber;

/**
 * Class InboundMessage.
 *
 * The `message` webhook event, translated. The webhook's `data` is the
 * argument list of the whatsapp-web.js listener: `data[0]` is the Message
 * object. Reading the raw array in three different places is how its shape
 * gets misread.
 */
final readonly class InboundMessage
{
    /**
     * `from` is who the chat is with: the person in a DM, the group in a
     * group. `author` is who wrote — only set in groups, as whatsapp-web.js
     * does. `fromMe` is never true here: whatsapp-web.js does not emit
     * `message` for the bot's own sends. `status@broadcast` (a status
     * update) arrives as a DM with an empty `fromPhone`, flagged by
     * `isBroadcast`.
     *
     * @param array<string, mixed> $raw The whatsapp-web.js Message object.
     */
    public function __construct(
        public string $id,
        public string $from,
        public string $fromPhone,
        public ?string $to,
        public ?string $body,
        public string $type,
        public bool $hasMedia,
        public ?array $mediaMetadata,
        public bool $isGroup,
        public bool $fromMe,
        public bool $hasUnresolvedSender,
        public Carbon $sentAt,
        public ?string $author = null,
        public string $authorPhone = '',
        public bool $isBroadcast = false,
        public array $raw = [],
    ) {
    }

    /**
     * Build from the whole webhook envelope: `{"name": "message", "data": [...]}`.
     * Returns null when the envelope is not a message we can make sense of.
     *
     * @param array<string, mixed> $envelope
     * @param string|null $wid The receiving bot's wid (`X-Wid` header).
     */
    public static function fromEnvelope(array $envelope, ?string $wid = null): ?self
    {
        if (($envelope['name'] ?? null) !== 'message') {
            return null;
        }

        $message = Arr::get($envelope, 'data.0');

        if (!is_array($message)) {
            return null;
        }

        return self::fromMessage($message, $wid);
    }

    /**
     * Build from the whatsapp-web.js Message object itself.
     *
     * @param array<string, mixed> $message
     */
    public static function fromMessage(array $message, ?string $wid = null): ?self
    {
        // `_serialized` is the global id; `id.id` alone may repeat across chats.
        $id = Arr::get($message, 'id._serialized') ?? Arr::get($message, 'id.id');

        if (!is_string($id) || $id === '') {
            return null;
        }

        $from = self::resolveFrom($message);
        $isGroup = PhoneNumber::isGroupWid($from) || PhoneNumber::isGroupWid($message['from'] ?? null);
        $author = $isGroup ? self::resolveAuthor($message) : null;
        $hasMedia = (bool) ($message['hasMedia'] ?? false);
        $timestamp = $message['timestamp'] ?? null;

        return new self(
            id: $id,
            from: $from,
            fromPhone: PhoneNumber::fromWid($from),
            to: $wid ?: (isset($message['to']) ? (string) $message['to'] : null),
            body: is_string($message['body'] ?? null) ? $message['body'] : null,
            type: (string) ($message['type'] ?? 'chat'),
            hasMedia: $hasMedia,
            mediaMetadata: $hasMedia ? self::extractMediaMetadata($message) : null,
            isGroup: $isGroup,
            fromMe: (bool) ($message['fromMe'] ?? false),
            hasUnresolvedSender: PhoneNumber::isLidWid($from) || PhoneNumber::isLidWid($author),
            sentAt: is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : Carbon::now(),
            raw: $message,
            author: $author,
            authorPhone: $author === null || PhoneNumber::isLidWid($author) ? '' : PhoneNumber::fromWid($author),
            isBroadcast: PhoneNumber::isBroadcastWid($from) || PhoneNumber::isBroadcastWid($message['from'] ?? null),
        );
    }

    /**
     * The original file name, as the sender's device reported it (documents
     * mostly — a photo taken in the app has none). Null without media.
     */
    public function mediaFilename(): ?string
    {
        return $this->mediaMetadata['filename'] ?? null;
    }

    /**
     * The media's mime type as the webhook reported it. Null without media.
     */
    public function mediaMimeType(): ?string
    {
        return $this->mediaMetadata['mimetype'] ?? null;
    }

    /**
     * The sender's wid, trying the alternative sources before giving up:
     * Zapmizer resolves lid→wid but admits failure, and then `from` arrives as
     * `84474155032797@lid`. `_data.from` usually carries the real wid in that
     * case; `author` is the sender inside a group.
     *
     * @param array<string, mixed> $message
     */
    private static function resolveFrom(array $message): string
    {
        return self::firstResolvedWid([
            $message['from'] ?? null,
            Arr::get($message, '_data.from._serialized'),
            Arr::get($message, '_data.from'),
            $message['author'] ?? null,
            Arr::get($message, '_data.author._serialized'),
            Arr::get($message, '_data.author'),
        ]) ?? (string) ($message['from'] ?? '');
    }

    /**
     * Who wrote inside a group: `from` is the group there, `author` the
     * person. Null when the payload carries no author at all; a `@lid`
     * author is kept as is (and flagged by `hasUnresolvedSender`).
     *
     * @param array<string, mixed> $message
     */
    private static function resolveAuthor(array $message): ?string
    {
        $candidates = [
            $message['author'] ?? null,
            Arr::get($message, '_data.author._serialized'),
            Arr::get($message, '_data.author'),
        ];

        if (($resolved = self::firstResolvedWid($candidates)) !== null) {
            return $resolved;
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The first candidate that is a real wid (non-empty, not a `@lid`).
     *
     * @param array<int, mixed> $candidates
     */
    private static function firstResolvedWid(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && !PhoneNumber::isLidWid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * What the webhook tells about the media. The bytes don't come along —
     * keep what can be kept for when they do.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private static function extractMediaMetadata(array $message): array
    {
        $data = Arr::get($message, '_data', []);

        return array_filter([
            'mimetype' => Arr::get($data, 'mimetype'),
            'filename' => Arr::get($data, 'filename'),
            'caption' => Arr::get($data, 'caption'),
            'size' => Arr::get($data, 'size'),
            'media_key' => Arr::get($data, 'mediaKey'),
            'direct_path' => Arr::get($data, 'directPath'),
            'deprecated_mms3_url' => Arr::get($data, 'deprecatedMms3Url'),
            'filehash' => Arr::get($data, 'filehash'),
        ], fn ($value) => $value !== null);
    }
}
