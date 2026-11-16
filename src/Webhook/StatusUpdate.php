<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;
use NotificationChannels\Zapmizer\Messages\Concerns\HasMessageStatus;
use NotificationChannels\Zapmizer\Messages\MessageStatus;

/**
 * The `message_status` webhook's data: a message sent by the number changed
 * status. QR numbers only.
 *
 * `ack` is on the `Messages\MessageStatus` scale, the one of the sent-message
 * tracking: compare by the number. The webhook only carries `-1` to `4`
 * (`0`, `SENDING`, arrives named `pending`); `-2`, `-3` and `-4` only exist on
 * the tracking. `status` is the name of that `ack` — a delivery named
 * `unknown`, or whose `ack` is off that range, is not read at all.
 *
 * `wappMessageId` is the same id the sent message's `links.self` shows once
 * the number sends it; a status may arrive before you know it, and the
 * messages sent from the phone have statuses too — ignore an id you do not
 * know.
 */
final class StatusUpdate
{
    use HasMessageStatus;

    /**
     * The names the webhook gives each `ack`.
     *
     * @var array<int, string>
     */
    private const NAMES = [
        MessageStatus::FAILED => 'failed',
        MessageStatus::SENDING => 'pending',
        MessageStatus::SENT => 'sent',
        MessageStatus::DELIVERED => 'delivered',
        MessageStatus::READ => 'read',
        MessageStatus::PLAYED => 'played',
    ];

    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $wappMessageId,
        public readonly ?string $to,
        public readonly int $ack,
        public readonly string $status,
        public readonly ?Carbon $occurredAt,
        public readonly array $raw = [],
    ) {
    }

    public static function fromData(mixed $data): ?self
    {
        $data = Fields::data($data);
        $id = $data === null ? null : Fields::identifier($data, 'wapp_message_id');
        $ack = $data === null ? null : Fields::int($data, 'ack');

        if ($id === null || $ack === null || !isset(self::NAMES[$ack]) || ($data['status'] ?? null) === 'unknown') {
            return null;
        }

        return new self(
            wappMessageId: $id,
            to: Fields::string($data, 'to'),
            ack: $ack,
            status: self::NAMES[$ack],
            occurredAt: Fields::time($data, 'timestamp'),
            raw: $data,
        );
    }

    public function messageStatusCode(): ?int
    {
        return $this->ack;
    }

    /**
     * Whether the message got at least this far (`reached(MessageStatus::DELIVERED)`
     * is true for delivered, read and played). Never true for a failure, nor
     * for a failure as the target.
     */
    public function reached(int $target): bool
    {
        return MessageStatus::reached($this->ack, $target);
    }
}
