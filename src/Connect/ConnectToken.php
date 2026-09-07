<?php

namespace NotificationChannels\Zapmizer\Connect;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

/**
 * Class ConnectToken.
 *
 * Result of exchanging the callback code: the team's Sanctum token on
 * Zapmizer, plus which team authorized.
 */
final readonly class ConnectToken
{
    public function __construct(
        public string $token,
        public ?int $teamId = null,
        public ?string $teamName = null,
    ) {
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $payload): self
    {
        $data = $payload['data'] ?? $payload;

        if (blank($data['token'] ?? null)) {
            throw ZapmizerConnectException::unexpectedResponse('missing token');
        }

        return new self(
            token: (string) $data['token'],
            teamId: isset($data['team_id']) ? (int) $data['team_id'] : null,
            teamName: isset($data['team_name']) ? (string) $data['team_name'] : null,
        );
    }
}
