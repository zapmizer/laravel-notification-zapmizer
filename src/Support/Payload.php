<?php

namespace NotificationChannels\Zapmizer\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Payload
{
    private const DIGITS = '/^[0-9]{1,18}\z/';

    private const ISO_8601 = '/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

    public static function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match(self::DIGITS, $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    public static function optionalCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && preg_match(self::DIGITS, $value) === 1 ? (int) $value : null;
    }

    public static function date(mixed $value, string $field, ?string $externalId = null): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = is_string($value) ? self::parseDate($value) : null;

        if ($date === null) {
            Log::warning('zapmizer: unreadable date.', [
                'field' => $field,
                'value' => $value,
                'external_id' => $externalId,
            ]);
        }

        return $date;
    }

    public static function url(mixed $value, bool $underscoreInHost = false): ?string
    {
        if (!is_string($value) || preg_match('/[\x00-\x20\x7F\\\\]/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || !self::isHost($parts['host'], $underscoreInHost)
        ) {
            return null;
        }

        if (isset($parts['port']) && !self::hasPort($value, $parts['port'])) {
            return null;
        }

        return $value;
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . strtolower($parts['host']) . ($port === null || $port === $default ? '' : ':' . $port);
    }

    private static function isHost(string $host, bool $underscoreInHost): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return preg_match($underscoreInHost ? '/^[A-Za-z0-9._-]+\z/' : '/^[A-Za-z0-9.-]+\z/', $host) === 1
            && self::hasValidPunycode($host);
    }

    /**
     * Every `xn--` label must decode the way the browser's `new URL()`
     * decodes it (UTS #46, non-transitional, with the bidi and joiner
     * checks): `xn--ao-bia.com` passes the character check above and the
     * browser refuses it. Only those labels go through IDNA — the rest of
     * the host keeps the rules above (an underscore, a hyphen at the edge),
     * which IDNA would refuse. `idn_to_utf8` comes from `ext-intl` or, without
     * it, from `symfony/polyfill-intl-idn`.
     */
    private static function hasValidPunycode(string $host): bool
    {
        foreach (explode('.', strtolower($host)) as $label) {
            if (!str_starts_with($label, 'xn--')) {
                continue;
            }

            $info = [];
            $decoded = idn_to_utf8($label, IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46, $info);

            if ($decoded === false || ($info['errors'] ?? 1) !== 0) {
                return false;
            }
        }

        return true;
    }

    private static function hasPort(string $url, int $port): bool
    {
        return preg_match('#^[^:]+://([^/?\#]*)#', $url, $authority) === 1
            && preg_match('/:\d+\z/', $authority[1]) === 1
            && $port >= 1
            && $port <= 65535;
    }

    private static function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match(self::ISO_8601, $value, $parts) !== 1) {
            return null;
        }

        $fraction = $parts[2] === '' ? '' : substr($parts[2], 0, 7);

        try {
            $date = CarbonImmutable::parse(substr($value, 0, 19) . $fraction . $parts[3]);
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $parts[1] ? $date : null;
    }
}
