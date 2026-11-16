<?php

namespace NotificationChannels\Zapmizer\Support;

/**
 * Class EmbedBrand.
 *
 * The `brand` the front passes to @zapmizer/embed. The iframe tags every
 * event with `source: "{brand}-embed"`, and the library drops any other
 * (`src/messages.ts` of the embed), so the brand must be the one of the
 * Zapmizer deploy the `base_uri` points at: each deploy emits its own
 * (`VITE_BRAND` on the server — `parli` on app.parlichat.com, `zapmizer`
 * by default). Wrong brand: the inbox never gets its `ready`.
 *
 * `zapmizer.inbox.brand` (ZAPMIZER_EMBED_BRAND) wins; empty, it comes from
 * the `base_uri` host.
 */
final class EmbedBrand
{
    public const ZAPMIZER = 'zapmizer';

    public const PARLI = 'parli';

    public static function resolve(): string
    {
        $baseUri = config('zapmizer.base_uri');

        return self::resolveFor(is_string($baseUri) ? $baseUri : null);
    }

    /**
     * The brand for a base URI that may not be in the config yet (the one
     * `zapmizer:install` is writing): `zapmizer.inbox.brand` still wins.
     */
    public static function resolveFor(?string $baseUri): string
    {
        $configured = config('zapmizer.inbox.brand');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return self::fromBaseUri($baseUri);
    }

    public static function fromBaseUri(?string $baseUri): string
    {
        $host = strtolower((string) parse_url((string) $baseUri, PHP_URL_HOST));

        return $host === 'parlichat.com' || str_ends_with($host, '.parlichat.com') ? self::PARLI : self::ZAPMIZER;
    }
}
