<?php

namespace NotificationChannels\Zapmizer\Connect;

use JsonSerializable;

/**
 * Class InstanceConnection.
 *
 * Mirror of GET /bot-instances/{id}/connection. The wizard polls this to
 * show the QR code and the pairing state; `number` is only filled once
 * `state === 'connected'`.
 */
final readonly class InstanceConnection implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $state,
        public string $stateLabel = '',
        public bool $isOnline = false,
        public bool $isUp = false,
        public ?string $qrcode = null,
        public ?string $qrcodeAvailableAt = null,
        public ?string $qrcodeExpiresAt = null,
        public ?string $number = null,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        return new self(
            id: (int) ($data['id'] ?? 0),
            state: (string) ($data['state'] ?? ''),
            stateLabel: (string) ($data['state_label'] ?? ''),
            isOnline: (bool) ($data['is_online'] ?? false),
            isUp: (bool) ($data['is_up'] ?? false),
            qrcode: $data['qrcode'] ?? null,
            qrcodeAvailableAt: $data['qrcode_available_at'] ?? null,
            qrcodeExpiresAt: $data['qrcode_expires_at'] ?? null,
            number: isset($data['number']) ? (string) $data['number'] : null,
        );
    }

    public function isConnected(): bool
    {
        return $this->state === 'connected' && filled($this->number);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'state_label' => $this->stateLabel,
            'is_online' => $this->isOnline,
            'is_up' => $this->isUp,
            'qrcode' => $this->qrcode,
            'qrcode_available_at' => $this->qrcodeAvailableAt,
            'qrcode_expires_at' => $this->qrcodeExpiresAt,
            'number' => $this->number,
        ];
    }
}
