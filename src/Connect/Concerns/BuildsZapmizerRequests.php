<?php

namespace NotificationChannels\Zapmizer\Connect\Concerns;

use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\PartnerClient;

trait BuildsZapmizerRequests
{
    /**
     * @throws InvalidArgumentException
     */
    protected function guardState(?string $state): void
    {
        if ($state === null) {
            return;
        }

        if ($state === '' || $state === '0' || preg_match('/^[\s\x{FEFF}\x{200B}\x{200E}]|[\s\x{FEFF}\x{200B}\x{200E}]$/u', $state)) {
            throw new InvalidArgumentException('The state must not be "" or "0", nor start or end with whitespace: Zapmizer trims it and drops it when empty, and the redirect would come back without it or changed.');
        }
    }

    protected function withoutNulls(array $body): array
    {
        return array_filter($body, fn ($value) => $value !== null);
    }

    protected function clampExpiresIn(?int $expiresIn): ?int
    {
        return $expiresIn === null ? null : min(max($expiresIn, PartnerClient::MIN_EXPIRES_IN), PartnerClient::MAX_EXPIRES_IN);
    }
}
