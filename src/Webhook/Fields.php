<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Carbon;

/**
 * Typed reads of a webhook's `data`. A value of the wrong type reads as
 * "absent" (null, false, []), never as an error: the payload comes from
 * outside, and a field Zapmizer changes must not turn a delivery into a 500.
 *
 * @internal
 */
final class Fields
{
    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A string that identifies something: empty counts as absent.
     *
     * @param array<string, mixed> $data
     */
    public static function identifier(array $data, string $key): ?string
    {
        $value = self::string($data, $key);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? null) === true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function number(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Seconds since 1970, as Zapmizer sends `timestamp`.
     *
     * @param array<string, mixed> $data
     */
    public static function time(array $data, string $key): ?Carbon
    {
        $value = self::int($data, $key);

        return $value === null ? null : Carbon::createFromTimestamp($value);
    }

    /**
     * The `data` object of an envelope; null when it is not an object.
     *
     * @return array<string, mixed>|null
     */
    public static function data(mixed $data): ?array
    {
        return is_array($data) && !array_is_list($data) ? $data : null;
    }
}
