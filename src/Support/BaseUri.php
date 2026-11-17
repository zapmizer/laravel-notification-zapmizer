<?php

namespace NotificationChannels\Zapmizer\Support;

/**
 * Class BaseUri.
 *
 * What `ZAPMIZER_BASE_URI` must look like: an absolute http(s) URL whose
 * path ends in `/api`, the prefix of every route of Zapmizer's API (the
 * partner routes too). Without it, a route answers a 404 that says nothing
 * about the credentials. Shared by `zapmizer:install --base-uri` and
 * `zapmizer:check`.
 */
final class BaseUri
{
    public const DEFAULT = 'https://app.zapmizer.com/api/';

    /**
     * The public API of each brand's deploy: what a committed file (the
     * `.env.example`) gets in place of a host only the developer reaches.
     */
    public const PUBLIC = [
        EmbedBrand::ZAPMIZER => self::DEFAULT,
        EmbedBrand::PARLI => 'https://app.parlichat.com/api/',
    ];

    /**
     * Why the value is not a base URI, or null when it is.
     */
    public static function problem(?string $uri): ?string
    {
        $uri = trim((string) $uri);

        if (Payload::url(rtrim($uri, '/') . '/connect/sessions', underscoreInHost: true) === null) {
            return 'não é uma URL http(s) absoluta';
        }

        $path = rtrim((string) parse_url($uri, PHP_URL_PATH), '/');

        if (!str_ends_with($path, '/api')) {
            return 'precisa terminar em /api (ex.: ' . self::DEFAULT . ')';
        }

        return null;
    }

    /**
     * The base URI fit for a committed file: a known deploy's stays; any
     * other host (a local server, a staging) becomes the public deploy of
     * its brand, or the default one for a brand the package does not know.
     */
    public static function shareable(string $uri, string $brand): string
    {
        if (EmbedBrand::knownDeploy($uri) !== null) {
            return $uri;
        }

        return self::PUBLIC[$brand] ?? self::DEFAULT;
    }

    public static function same(?string $a, ?string $b): bool
    {
        return rtrim(trim((string) $a), '/') === rtrim(trim((string) $b), '/');
    }
}
