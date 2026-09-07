<?php

namespace NotificationChannels\Zapmizer\Support;

use Illuminate\Support\Str;

/**
 * Class PhoneNumber.
 *
 * Normalizes numbers to E.164 without the `+` (completing the configured
 * country code on national numbers), and produces the Brazilian
 * ninth-digit variants. WhatsApp wids don't always carry the extra 9 the
 * person typed when registering (and vice versa) — comparing raw strings
 * would make a known number look unknown.
 */
final class PhoneNumber
{
    /**
     * Digits only. `+55 (81) 99999-8888` → `5581999998888`.
     */
    public static function digits(?string $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    /**
     * Extract the number from a wid (`5581999998888@c.us`, `...@lid`, `...@g.us`).
     */
    public static function fromWid(?string $wid): string
    {
        return self::digits(Str::before((string) $wid, '@'));
    }

    public static function isGroupWid(?string $wid): bool
    {
        return Str::endsWith((string) $wid, '@g.us');
    }

    /**
     * The "linked id" wid: WhatsApp delivers `84474155032797@lid` when it does
     * not expose the real number. The digits of a LID are NOT a phone number.
     */
    public static function isLidWid(?string $wid): bool
    {
        return Str::endsWith((string) $wid, '@lid');
    }

    /**
     * Complete the country code when the number came in national format.
     *
     * The criterion is length, not prefix: 10 or 11 digits is area code +
     * 8/9-digit subscriber; anything longer is taken as already E.164 and
     * passes through untouched. The code prepended is
     * `zapmizer.default_country_code` (`55` by default) — or the one given.
     * A leading carrier zero (`081 99999-8888`) is dropped first — no E.164
     * number starts with zero, and without the cut it would be 12 digits
     * that never match a wid.
     */
    public static function normalize(?string $value, ?string $countryCode = null): string
    {
        $digits = ltrim(self::digits($value), '0');
        $length = Str::length($digits);
        $countryCode = self::digits($countryCode ?? self::defaultCountryCode());

        return ($length === 10 || $length === 11) && $countryCode !== '' ? $countryCode . $digits : $digits;
    }

    /**
     * The country code national numbers are completed with.
     */
    public static function defaultCountryCode(): string
    {
        return (string) (function_exists('config') ? config('zapmizer.default_country_code', '55') : '55');
    }

    public static function isBroadcastWid(?string $wid): bool
    {
        return Str::endsWith((string) $wid, '@broadcast');
    }

    /**
     * The number plus its with/without-ninth-digit variants, to match what
     * was registered against what WhatsApp delivers. Only touches Brazilian
     * mobile numbers (55 + area code + 8 or 9 digits whose subscriber part
     * starts with 6-9) — a landline never grows a fake mobile twin.
     *
     * @return array<int, string>
     */
    public static function variants(?string $value): array
    {
        $digits = self::normalize($value);
        $variants = [$digits];

        if (Str::startsWith($digits, '55')) {
            $areaCode = Str::substr($digits, 2, 2);
            $rest = Str::substr($digits, 4);

            if (Str::length($rest) === 9 && Str::startsWith($rest, '9') && self::isMobileSubscriber(Str::substr($rest, 1))) {
                $variants[] = '55' . $areaCode . Str::substr($rest, 1);
            }

            if (Str::length($rest) === 8 && self::isMobileSubscriber($rest)) {
                $variants[] = '55' . $areaCode . '9' . $rest;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * An 8-digit subscriber that was a mobile before the ninth digit: starts with 6-9.
     */
    private static function isMobileSubscriber(string $rest): bool
    {
        return (bool) preg_match('/^[6-9]/', $rest);
    }
}
