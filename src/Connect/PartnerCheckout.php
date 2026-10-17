<?php

namespace NotificationChannels\Zapmizer\Connect;

use Carbon\CarbonImmutable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

final class PartnerCheckout
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
        $url = $payload['url'] ?? null;

        if (!is_string($url) || $url === '') {
            throw ZapmizerConnectException::unexpectedResponse('missing checkout url');
        }

        return new self(
            url: $url,
            expiresAt: Payload::date($payload['expires_at'] ?? null, 'expires_at', $externalId),
        );
    }
}
