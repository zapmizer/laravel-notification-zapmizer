<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * The `message_status` webhook's data: a message sent by the number changed
 * status. QR numbers only.
 *
 * `ack` follows the scale of the sent-message tracking (`-1` failed, `0`
 * pending, `1` sent, `2` delivered, `3` read, `4` played): compare by the
 * number. `status` is the name of that `ack` — a delivery whose name is
 * `unknown`, or whose `ack` is off the scale, is not read at all.
 *
 * `wappMessageId` is the same id the sent message's `links.self` shows once
 * the number sends it; a status may arrive before you know it, and the
 * messages sent from the phone have statuses too — ignore an id you do not
 * know.
 */
final class MessageStatus
{
    public const ACK_FAILED = -1;

    public const ACK_PENDING = 0;

    public const ACK_SENT = 1;

    public const ACK_DELIVERED = 2;

    public const ACK_READ = 3;

    public const ACK_PLAYED = 4;

    /**
     * @var array<int, string>
     */
    public const NAMES = [
        self::ACK_FAILED => 'failed',
        self::ACK_PENDING => 'pending',
        self::ACK_SENT => 'sent',
        self::ACK_DELIVERED => 'delivered',
        self::ACK_READ => 'read',
        self::ACK_PLAYED => 'played',
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

    public function isFailed(): bool
    {
        return $this->ack === self::ACK_FAILED;
    }

    /**
     * Whether the message got at least this far (`reached(ACK_DELIVERED)` is
     * true for delivered, read and played). Never true for a failed one.
     */
    public function reached(int $ack): bool
    {
        return !$this->isFailed() && $this->ack >= $ack;
    }
}
