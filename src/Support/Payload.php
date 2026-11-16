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
