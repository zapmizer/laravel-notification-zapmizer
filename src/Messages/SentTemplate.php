<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * Class SentTemplate.
 *
 * What `POST /template-messages` answered (official number only). `wamid`
 * is the WhatsApp id of the message, null when the send went through a
 * queue; `previewText` is the text as the contact received it, with the
 * variables filled in. There is no `id` nor `links.self`: a template send
 * is not followed through `Zapmizer::track()`.
 */
final class SentTemplate
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly ?string $wamid,
        public readonly ?string $widFrom = null,
        public readonly ?string $widTo = null,
        public readonly ?string $previewText = null,
        private readonly array $payload = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            wamid: TrackingPayload::text($payload['wamid'] ?? null),
            widFrom: TrackingPayload::text($payload['wid_from'] ?? null),
            widTo: TrackingPayload::text($payload['wid_to'] ?? null),
            previewText: TrackingPayload::text($payload['preview_text'] ?? null),
            payload: $payload,
        );
    }

    /**
     * The decoded body of the answer, as it came.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->payload;
    }
}
