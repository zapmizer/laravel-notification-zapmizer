<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;

final class ScreenCodeEmbedResponse extends EmbedSessionResponse
{
    protected function refusalCode(ZapmizerApiException $refusal): ?string
    {
        if (!in_array($refusal->status(), [402, 403, 422], true)) {
            return self::UNAVAILABLE;
        }

        return match ($refusal->error()) {
            null => [403 => self::REAUTH_REQUIRED, 422 => 'rejected'][$refusal->status()] ?? self::UNAVAILABLE,
            ErrorCode::MISSING_ABILITY, ErrorCode::NOT_A_PARTNER_CONNECTION => self::REAUTH_REQUIRED,
            default => $refusal->error(),
        };
    }

    protected function refusalStatus(ZapmizerApiException $refusal): int
    {
        return $this->refusalCode($refusal) === self::UNAVAILABLE ? 503 : 422;
    }
}
