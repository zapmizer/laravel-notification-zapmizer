<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `contact_number_changed` webhook's data: a contact moved to another
 * number. QR numbers only.
 *
 * - `isContact`: in the connected number's contacts.
 *
 * Read only when `old_wid` and `new_wid` are there; any other field missing or
 * of the wrong type reads as null, false or []. `occurredAt` comes from
 * `timestamp` (seconds, UTC).
 */
final class ContactNumberChange
{
    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $oldWid,
        public readonly string $newWid,
        public readonly bool $isContact,
        public readonly ?Carbon $occurredAt,
        public readonly array $raw = [],
    ) {
    }

    public static function fromData(mixed $data): ?self
    {
        $data = Fields::data($data);
        $oldWid = $data === null ? null : Fields::identifier($data, 'old_wid');
        $newWid = $data === null ? null : Fields::identifier($data, 'new_wid');

        if ($oldWid === null || $newWid === null) {
            return null;
        }

        return new self(
            oldWid: $oldWid,
            newWid: $newWid,
            isContact: Fields::bool($data, 'is_contact'),
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }
}
