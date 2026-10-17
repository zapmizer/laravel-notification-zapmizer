<?php

namespace NotificationChannels\Zapmizer\Webhook;

/**
 * The `connection` webhook's data: the number connected, dropped or is
 * waiting for a QR. Official numbers only send `connected` and
 * `disconnected`.
 *
 * `kind` only comes with `disconnected`: `banned` (blocked by WhatsApp),
 * `logged_out` (needs a new QR), `conflict` (another WhatsApp Web session),
 * `connection_lost` (turning it back on fixes it). A value new to the
 * package comes through as-is. `reason` is the drop reason as WhatsApp gave
 * it. The `off` state only exists on the API, never on the webhook.
 */
final class ConnectionState
{
    public const QR_PENDING = 'qr_pending';

    public const CONNECTING = 'connecting';

    public const CONNECTED = 'connected';

    public const DISCONNECTED = 'disconnected';

    public const KIND_BANNED = 'banned';

    public const KIND_LOGGED_OUT = 'logged_out';

    public const KIND_CONFLICT = 'conflict';

    public const KIND_CONNECTION_LOST = 'connection_lost';

    /**
     * @param array<string, mixed> $raw The webhook's `data`.
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $reason,
        public readonly ?string $kind,
        public readonly array $raw = [],
    ) {
    }

    public static function fromData(mixed $data): ?self
    {
        $data = Fields::data($data);
        $state = $data === null ? null : Fields::string($data, 'state');

        if (!in_array($state, [self::QR_PENDING, self::CONNECTING, self::CONNECTED, self::DISCONNECTED], true)) {
            return null;
        }

        return new self(
            state: $state,
            reason: Fields::string($data, 'reason'),
            kind: Fields::identifier($data, 'kind'),
            raw: $data,
        );
    }

    public function isConnected(): bool
    {
        return $this->state === self::CONNECTED;
    }

    public function isDisconnected(): bool
    {
        return $this->state === self::DISCONNECTED;
    }
}
