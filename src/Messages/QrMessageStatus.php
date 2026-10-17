<?php

namespace NotificationChannels\Zapmizer\Messages;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Messages\Concerns\HasMessageStatus;

/**
 * Class QrMessageStatus.
 *
 * Mirror of GET /sent-messages/{id}: where a send from a QR number is now.
 * It starts at `QUEUED`/`SENDING` and moves as WhatsApp confirms.
 * `wappMessageId` shows up once the number has sent it — the same id the
 * `message_status` webhook carries, so this is how a status without owner
 * is tied back to the send.
 *
 * Compare `status` by the number (see `MessageStatus`); `statusName` and
 * `statusLabel` are text for display.
 */
final class QrMessageStatus
{
    use HasMessageStatus;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly int $status,
        public readonly ?string $wappMessageId = null,
        public readonly ?string $widFrom = null,
        public readonly ?string $widTo = null,
        public readonly ?string $statusName = null,
        public readonly ?string $statusLabel = null,
        public readonly ?string $error = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $timestamp = null,
        public readonly ?string $selfUrl = null,
        private readonly array $payload = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $payload): self
    {
        $data = TrackingPayload::data($payload);

        return new self(
            id: TrackingPayload::id($data),
            status: TrackingPayload::status($data),
            wappMessageId: TrackingPayload::text($data['wapp_message_id'] ?? null),
            widFrom: TrackingPayload::text($data['wid_from'] ?? null),
            widTo: TrackingPayload::text($data['wid_to'] ?? null),
            statusName: TrackingPayload::text($data['message_status_name'] ?? null),
            statusLabel: TrackingPayload::text($data['message_status_label'] ?? null),
            error: TrackingPayload::text($data['error'] ?? null),
            errorMessage: TrackingPayload::text($data['error_message'] ?? null),
            timestamp: is_int($data['timestamp'] ?? null) ? $data['timestamp'] : null,
            selfUrl: TrackingPayload::selfUrl($data),
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
