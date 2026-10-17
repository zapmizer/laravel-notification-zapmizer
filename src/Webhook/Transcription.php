<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `message_transcribed` webhook's data: an audio of the chat became text,
 * once per message. `wappMessageId` is the same `id.id` of the `message`
 * event. `source`: `auto` (the chat has `auto_transcribe_audio`), `screen`,
 * `agent`, `watcher` or `manual`. `occurredAt` is when the transcription
 * was ready.
 *
 * - `wappMessageId`: The audio.
 * - `chatWid`: The chat (`...@c.us` or `...@g.us`).
 * - `model`: The model that transcribed it.
 *
 * Read only when `wapp_message_id` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class Transcription
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $wappMessageId,
        public readonly ?string $chatWid,
        public readonly ?string $text,
        public readonly ?float $durationSeconds,
        public readonly ?string $model,
        public readonly ?string $source,
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
            text: Fields::string($data, 'text'),
            durationSeconds: Fields::number($data, 'duration_seconds'),
            model: Fields::string($data, 'model'),
            source: Fields::string($data, 'source'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
