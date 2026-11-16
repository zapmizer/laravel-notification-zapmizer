<?php

namespace NotificationChannels\Zapmizer\Support;

/**
 * Class ConnectionWebhook.
 *
 * The one reader of `zapmizer.webhook.per_connection`: the provider asks it
 * whether to register the per-connection webhook route, the connect flow
 * whether to send that route as the session's webhook URL. Absent, `null`
 * or not a boolean word, the option is off.
 */
final class ConnectionWebhook
{
    public static function enabled(): bool
    {
        return filter_var(config('zapmizer.webhook.per_connection', false), FILTER_VALIDATE_BOOLEAN);
    }
}
