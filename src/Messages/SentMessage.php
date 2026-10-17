<?php

namespace NotificationChannels\Zapmizer\Messages;

use NotificationChannels\Zapmizer\Messages\Concerns\HasMessageStatus;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * Class SentMessage.
 *
 * What `POST /messages` answered: the message was accepted for sending, not
 * delivered. Follow it with `Zapmizer::track()`; `selfUrl` is the
 * `links.self` the API gave (it points to `sent-messages` on a QR number and
 * to `meta-messages` on the official one — never build it by hand).
 *
 * - `id` is always a string (the API answers a string on QR and an integer
 *   on the official number). On the official number `id` and `selfUrl` are
 *   null when the send went through a queue: there is nothing to track, and
 *   it is not an error.
 * - `widTo` is the recipient as Zapmizer checked it on WhatsApp (it may add
 *   the `55` or drop the ninth digit): use it, not the `to` you sent, as the
 *   contact's key — it is what comes back in the `message` webhook.
 * - The answer of the legacy shape (no `api-version`, QR number) comes
 *   wrapped in `data`; it is read the same way.
 *
 * Every field is optional in the contract, so every field may be null.
 * `raw()` is the decoded body as it came.
 */
final class SentMessage
{
    use HasMessageStatus;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $selfUrl,
        public readonly ?string $widFrom = null,
        public readonly ?string $widTo = null,
        public readonly ?int $status = null,
        public readonly ?string $statusName = null,
        public readonly ?string $type = null,
        private readonly array $payload = [],
    ) {
    }

    public function messageStatusCode(): ?int
    {
        return $this->status;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return new self(
            id: self::id($data['id'] ?? null),
            selfUrl: Payload::url($data['links']['self'] ?? null, underscoreInHost: true),
            widFrom: self::text($data['wid_from'] ?? null),
            widTo: self::text($data['wid_to'] ?? null),
            status: is_int($data['message_status'] ?? null) ? $data['message_status'] : null,
            statusName: self::text($data['message_status_name'] ?? null),
            type: self::text($data['type'] ?? null),
            payload: $payload,
        );
    }

    /**
     * Whether `Zapmizer::track()` can follow this send: its `links.self` is
     * a `sent-messages` or `meta-messages` link. False for an official send
     * that went through a queue (no link) and for the legacy shape (a
     * `/messages/{id}` link, sent without `api-version`).
     */
    public function isTrackable(): bool
    {
        return $this->id !== null && TrackingPayload::link($this->selfUrl) !== null;
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

    private static function id(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
