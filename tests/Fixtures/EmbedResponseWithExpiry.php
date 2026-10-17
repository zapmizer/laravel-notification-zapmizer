<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Connect\EmbedSession;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;

final class EmbedResponseWithExpiry extends EmbedSessionResponse
{
    protected function sessionPayload(EmbedSession $session): array
    {
        return parent::sessionPayload($session) + ['expires_at' => $session->expiresAt?->toIso8601String()];
    }
}
