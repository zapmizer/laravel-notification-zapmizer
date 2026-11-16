<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;

final class StatusEmbedResponse extends EmbedSessionResponse
{
    public static int $status = 401;

    protected function refusalStatus(ZapmizerApiException $refusal): int
    {
        return static::$status;
    }
}
