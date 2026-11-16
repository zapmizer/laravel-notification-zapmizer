<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `group_membership` webhook's data: the number joined or left a group.
 * QR numbers only. `kind` is `joined`, `left` or `removed` (taken out by
 * someone else).
 *
 * - `kind`: `joined`, `left` or `removed`.
 * - `by`: Who made the change.
 *
 * Read only when `group_wid` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class GroupMembership
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $groupWid,
        public readonly ?string $kind,
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
            kind: Fields::string($data, 'kind'),
            by: Fields::string($data, 'by'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
