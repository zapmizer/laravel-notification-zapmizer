<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `group_join_request` webhook's data: someone asked to join a group.
 * QR numbers only.
 *
 * - `requester`: Who asked.
 *
 * Read only when `group_wid` is there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class GroupJoinRequest
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $groupWid,
        public readonly ?string $requester,
        /** @var array<int, string> */
        public readonly array $participants,
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
            requester: Fields::string($data, 'requester'),
            participants: Fields::strings($data, 'participants'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
