<?php

namespace NotificationChannels\Zapmizer\Support;

/**
 * Class ConnectWebhook.
 *
 * The one reader of `zapmizer.connect.webhook`: whether the connect asks
 * Zapmizer for a webhook on each user's connection (the session's
 * `webhook_url`), apart from whether the package registers the webhook
 * route (`zapmizer.routes.webhook`). Unset, the route group decides, as
 * before 2.1. Kept parseable on PHP 8.0.
 */
final class ConnectWebhook
{
    /**
     * What the app configured: `null` when absent, `null`, empty or not a
     * boolean word (a typo follows the route group, see invalid()); otherwise
     * the boolean.
     */
    public static function configured(): ?bool
    {
        $value = config('zapmizer.connect.webhook');

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Whether the key is set to something that is not a boolean word, which
     * configured() reads as unset.
     */
    public static function invalid(): bool
    {
        $value = config('zapmizer.connect.webhook');

        return $value !== null && $value !== '' && self::configured() === null;
    }

    /**
     * Whether the session asks Zapmizer for a webhook. The config wins;
     * without it, not when the package routes are on and the webhook group
     * off (a connect-only app); with the package routes off, the app mounts
     * the webhook route itself, so it asks.
     */
    public static function asked(): bool
    {
        return self::configured() ?? (!RouteGroups::routesEnabled() || RouteGroups::switchedOn(RouteGroups::WEBHOOK));
    }
}
