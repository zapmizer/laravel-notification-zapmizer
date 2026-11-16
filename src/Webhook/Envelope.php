<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * The envelope every catalog webhook comes in: `{id, version, created_at,
 * wid, name, data}`. `id` is the same on every retry of a delivery (deduplicate on it).
 * `createdAt` is when Zapmizer generated the event (UTC) — for `connection`,
 * apply the state with the most recent one. `wid` is the number that
 * generated the event, `null` on `connection` events from before the number
 * is ready. `version` is the webhook's version (`2024-02-27`, the one this
 * package reads; `2026-10-20` is the message contract, whose
 * `message_received`/`message_sent` it does not), `null` from a Zapmizer
 * before 1.148.17.
 *
 * Fields missing or of the wrong type read as null.
 */
final class Envelope
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly ?Carbon $createdAt,
        public readonly ?string $wid,
        public readonly ?string $version = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload The decoded webhook body.
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            id: Fields::identifier($payload, 'id'),
            name: (string) Fields::string($payload, 'name'),
            createdAt: self::parseTime(Fields::identifier($payload, 'created_at')),
            wid: Fields::identifier($payload, 'wid'),
            version: Fields::identifier($payload, 'version'),
        );
    }

    /**
     * Strict ISO 8601 with a zone, as Zapmizer sends it — the same reading
     * as the rest of the package (`Support\Payload::date`, which logs an
     * unreadable value). Anything else is null.
     */
    private static function parseTime(?string $value): ?Carbon
    {
        $date = Payload::date($value, 'created_at');

        return $date === null ? null : Carbon::instance($date);
    }
}
