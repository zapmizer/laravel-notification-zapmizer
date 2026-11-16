<?php

namespace NotificationChannels\Zapmizer\Support;

use NotificationChannels\Zapmizer\Contracts\Transport;
use Throwable;

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

    /**
     * The brands the package knows, with the name people read.
     */
    public const NAMES = [
        self::ZAPMIZER => 'Zapmizer',
        self::PARLI => 'Parli',
    ];

    /**
     * Where a deploy publishes its brand: the web app manifest the server's
     * Vite build links from `resources/brands/{VITE_BRAND}/public/assets`
     * (`resources/bundler/plugins/brand-public-assets-plugin.ts` of the
     * server) — the same `VITE_BRAND` its embed bridge puts in `source`.
     */
    public const MANIFEST_PATH = 'brand/manifest.json';

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
        $configured = self::configured();

        return $configured ?? self::fromBaseUri($baseUri);
    }

    /**
     * `zapmizer.inbox.brand` (ZAPMIZER_EMBED_BRAND), or null when empty.
     */
    public static function configured(): ?string
    {
        $configured = config('zapmizer.inbox.brand');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    public static function fromBaseUri(?string $baseUri): string
    {
        return self::knownDeploy($baseUri) ?? self::ZAPMIZER;
    }

    /**
     * The brand of a deploy known by its host — app.zapmizer.com and
     * parlichat.com (any subdomain) — or null for any other host (a local
     * server, a staging), whose brand only the deploy itself can tell.
     */
    public static function knownDeploy(?string $baseUri): ?string
    {
        $host = strtolower((string) parse_url((string) $baseUri, PHP_URL_HOST));

        if ($host === 'parlichat.com' || str_ends_with($host, '.parlichat.com')) {
            return self::PARLI;
        }

        return $host === 'app.zapmizer.com' ? self::ZAPMIZER : null;
    }

    /**
     * The `{web root}/brand/manifest.json` of the deploy: the base URI
     * without its trailing `/api`.
     */
    public static function manifestUrl(string $baseUri): ?string
    {
        $parts = parse_url(trim($baseUri));

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $path = preg_replace('#/api$#', '', rtrim($parts['path'] ?? '', '/'));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme']) . '://' . $parts['host'] . $port . $path . '/' . self::MANIFEST_PATH;
    }

    /**
     * Asks the deploy for its brand: `GET {web root}/brand/manifest.json`,
     * a static file with no auth and no side effect, whose `name` is the
     * brand's name (`Parli`, `Zapmizer`). Null when the deploy does not
     * answer, answers something else, or names a brand the package does
     * not know.
     */
    public static function discover(string $baseUri, ?Transport $transport = null): ?string
    {
        $url = self::manifestUrl($baseUri);

        if ($url === null) {
            return null;
        }

        try {
            $response = ($transport ?? app(Transport::class))->send('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'connect_timeout' => 3,
                'timeout' => 5,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $manifest = json_decode((string) $response->getBody(), true);
        $name = is_array($manifest) && is_string($manifest['name'] ?? null) ? strtolower(trim($manifest['name'])) : '';

        return array_key_exists($name, self::NAMES) ? $name : null;
    }

    /**
     * The brand's name for people (`Parli`, `Zapmizer`); an unknown brand
     * is capitalized.
     */
    public static function name(?string $brand = null): string
    {
        $brand = $brand ?? self::resolve();

        return self::NAMES[$brand] ?? ucfirst($brand);
    }
}
