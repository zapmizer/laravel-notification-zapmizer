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

    public static function same(?string $a, ?string $b): bool
    {
        return rtrim(trim((string) $a), '/') === rtrim(trim((string) $b), '/');
    }
}
