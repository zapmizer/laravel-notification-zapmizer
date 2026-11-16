<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `group_updated` webhook's data: the group's name, description, picture
 * or settings changed. QR numbers only. `change` says what: `subject`,
 * `description`, `picture`, `announce`, `restrict`...
 *
 * - `change`: What changed.
 * - `by`: Who made the change.
 *
 * Read only when `group_wid` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class GroupUpdate
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $groupWid,
        public readonly ?string $change,
        public readonly ?string $body,
        public readonly ?string $by,
        public readonly ?Carbon $occurredAt,
        public readonly array $raw = [],
    ) {
    }

    public static function fromData(mixed $data): ?self
    {
        $data = Fields::data($data);
        $groupWid = $data === null ? null : Fields::identifier($data, 'group_wid');

        if ($groupWid === null) {
            return null;
        }

        return new self(
            groupWid: $groupWid,
            change: Fields::string($data, 'change'),
            body: Fields::string($data, 'body'),
            by: Fields::string($data, 'by'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
