<?php

namespace NotificationChannels\Zapmizer\Connect;

use JsonSerializable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

/**
 * Class ConnectSession.
 *
 * An authorization session created on Zapmizer: `url` is the hosted page the
 * popup opens, where the end user picks the team to connect.
 */
final readonly class ConnectSession implements JsonSerializable
{
    public function __construct(
        public string $url,
        public ?string $expiresAt = null,
    ) {
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        if (blank($data['url'] ?? null)) {
            throw ZapmizerConnectException::unexpectedResponse('missing connect session url');
        }

        return new self(
            url: (string) $data['url'],
            expiresAt: isset($data['expires_at']) ? (string) $data['expires_at'] : null,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'url' => $this->url,
            'expires_at' => $this->expiresAt,
        ];
    }
}
