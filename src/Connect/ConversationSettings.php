<?php

namespace NotificationChannels\Zapmizer\Connect;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

/**
 * Class ConversationSettings.
 *
 * Mirror of GET/PUT /bot-instances/{id}/conversation-settings: the
 * settings of one conversation of the number. `wid` is the contact or the
 * group as Zapmizer normalized it (`5581999998888@c.us`, `...@g.us`).
 * `autoTranscribeAudio` transcribes every audio received in the
 * conversation, groups included, and delivers the text in the
 * `message_transcribed` webhook event. A conversation never adjusted answers
 * the defaults.
 */
final class ConversationSettings
{
    public function __construct(
        public readonly string $wid,
        public readonly bool $autoTranscribeAudio,
    ) {
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $payload): self
    {
        if (!is_string($payload['wid'] ?? null) || !is_bool($payload['auto_transcribe_audio'] ?? null)) {
            throw ZapmizerConnectException::unexpectedResponse('conversation settings response carries no `wid` and `auto_transcribe_audio`');
        }

        return new self($payload['wid'], $payload['auto_transcribe_audio']);
    }
}
