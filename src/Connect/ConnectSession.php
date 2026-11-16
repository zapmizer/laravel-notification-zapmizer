<?php

namespace NotificationChannels\Zapmizer\Connect;

use Carbon\CarbonImmutable;
use JsonSerializable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * Class ConnectSession.
 *
 * An authorization session created on Zapmizer: `url` is the hosted page the
 * popup opens, where the end user picks the team to connect.
 */
final class ConnectSession implements JsonSerializable
{
    public function __construct(
        public readonly string $url,
        public readonly ?CarbonImmutable $expiresAt = null,
    ) {
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $payload, ?string $externalId = null): self
    {
        $data = $payload['data'] ?? $payload;

        if (blank($data['url'] ?? null)) {
            throw ZapmizerConnectException::unexpectedResponse('missing connect session url');
        }

        return new self(
            url: (string) $data['url'],
            expiresAt: Payload::date($data['expires_at'] ?? null, 'expires_at', $externalId),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'url' => $this->url,
            'expires_at' => $this->expiresAt?->toIso8601String(),
        ];
    }
}
