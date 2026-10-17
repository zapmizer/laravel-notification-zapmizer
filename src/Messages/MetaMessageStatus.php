<?php

namespace NotificationChannels\Zapmizer\Messages;

use Carbon\CarbonImmutable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Messages\Concerns\HasMessageStatus;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * Class MetaMessageStatus.
 *
 * Mirror of GET /meta-messages/{id}: where a send from the official number
 * (Meta's API) is now. `errorReason` is Meta's reason when it failed.
 * The official number sends no `message_status` webhook: this endpoint is
 * the only way to follow the delivery.
 *
 * Compare `status` by the number (see `MessageStatus`); `statusName` is
 * text for display.
 */
final class MetaMessageStatus
{
    use HasMessageStatus;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly int $status,
        public readonly ?int $botInstanceId = null,
        public readonly ?string $wamid = null,
        public readonly ?string $widFrom = null,
        public readonly ?string $widTo = null,
        public readonly ?string $statusName = null,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $errorReason = null,
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly ?string $selfUrl = null,
        private readonly array $payload = [],
    ) {
    }

    public function messageStatusCode(): ?int
    {
        return $this->status;
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
            botInstanceId: Payload::positiveId($data['bot_instance_id'] ?? null),
            wamid: TrackingPayload::text($data['wamid'] ?? null),
            widFrom: TrackingPayload::text($data['wid_from'] ?? null),
            widTo: TrackingPayload::text($data['wid_to'] ?? null),
            statusName: TrackingPayload::text($data['message_status_name'] ?? null),
            mediaUrl: TrackingPayload::text($data['media_url'] ?? null),
            errorReason: TrackingPayload::text($data['error_reason'] ?? null),
            createdAt: Payload::date($data['created_at'] ?? null, 'created_at'),
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
