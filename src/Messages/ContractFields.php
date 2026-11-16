<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * Tolerant reads of the message contract's fields: a missing field or one
 * of the wrong type reads as null (or the given default), never as an error.
 *
 * @internal
 */
final class ContractFields
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_float($value) && is_finite($value) && floor($value) === $value ? (int) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function number(array $data, string $key): int|float|null
    {
        $value = $data[$key] ?? null;

        return is_int($value) || (is_float($value) && is_finite($value)) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        return is_bool($data[$key] ?? null) ? $data[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : null;
    }

    /**
     * The strings of a list, the rest dropped.
     *
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
