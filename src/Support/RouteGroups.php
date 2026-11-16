<?php

namespace NotificationChannels\Zapmizer\Support;

/**
 * Class RouteGroups.
 *
 * The one reader of the route switches. `zapmizer.routes.enabled` is the
 * master switch (false: no package route at all); under it each group is
 * registered only when its own key is on, and every group is off by
 * default: an app that only sends gets no route.
 *
 * - `connect`: connect and reconnect (`zapmizer.connect.*`);
 * - `webhook`: the signed webhook (`zapmizer.webhook`, and
 *   `zapmizer.webhook.connection` with `webhook.per_connection`);
 * - `inbox`: the embedded inbox (`zapmizer.inbox.*`);
 * - `verify_number`: the start of the number verification
 *   (`zapmizer.verify_number`).
 *
 * Kept parseable on PHP 8.0: the provider uses it on every boot.
 */
final class RouteGroups
{
    public const CONNECT = 'connect';

    public const WEBHOOK = 'webhook';

    public const INBOX = 'inbox';

    public const VERIFY_NUMBER = 'verify_number';

    public const ALL = [self::CONNECT, self::WEBHOOK, self::INBOX, self::VERIFY_NUMBER];

    /**
     * The master switch: anything but `false` keeps it on, as before.
     */
    public static function routesEnabled(): bool
    {
        return config('zapmizer.routes.enabled', true) !== false;
    }

    /**
     * Whether the package registers the group's routes.
     */
    public static function enabled(string $group): bool
    {
        return self::routesEnabled() && self::switchedOn($group);
    }

    /**
     * Whether the app asked for the group, the master switch aside (with
     * it off, the app mounts the routes itself).
     */
    public static function switchedOn(string $group): bool
    {
        return filter_var(config("zapmizer.routes.{$group}", false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The environment variable of the group: `ZAPMIZER_ROUTES_CONNECT`...
     */
    public static function env(string $group): string
    {
        return 'ZAPMIZER_ROUTES_' . strtoupper($group);
    }
}
