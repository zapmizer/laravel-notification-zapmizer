<?php

namespace NotificationChannels\Zapmizer\Connect;

use Carbon\CarbonImmutable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * `url` is only guaranteed on a result of fromNeedsReconnect(), the one
 * InstanceClient::reconnect() returns for NEEDS_CLIENT.
 */
final class ReconnectResult
{
    public const ONLINE = 'online';

    public const STARTING = 'starting';

    public const NEEDS_CLIENT = 'needs_client';

    public function __construct(
        public readonly string $status,
        public readonly ?string $url = null,
        public readonly ?CarbonImmutable $expiresAt = null,
    ) {
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function fromNeedsReconnect(array $payload): self
    {
        $url = Payload::url($payload['url'] ?? null);

        if ($url === null) {
            throw ZapmizerConnectException::unexpectedResponse('invalid reconnect url');
        }

        return new self(
            status: self::NEEDS_CLIENT,
            url: $url,
            expiresAt: Payload::date($payload['expires_at'] ?? null, 'expires_at'),
        );
    }

    public function isOnline(): bool
    {
        return $this->status === self::ONLINE;
    }

    public function isStarting(): bool
    {
        return $this->status === self::STARTING;
    }

    public function needsClient(): bool
    {
        return $this->status === self::NEEDS_CLIENT;
    }
}
