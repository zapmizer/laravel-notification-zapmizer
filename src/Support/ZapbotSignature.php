<?php

namespace NotificationChannels\Zapmizer\Support;

/**
 * Class ZapbotSignature.
 *
 * Validates Zapbot's HMAC-SHA256 signatures (`t={timestamp},v1={hmac}`),
 * computed over `"{timestamp}.{body}"` with the verification's webhook
 * secret. The same scheme is used in two places:
 *
 * - the `X-Zapbot-Signature` header of webhook deliveries (body = raw JSON);
 * - the `sig` query param of the hosted page's signed return redirect
 *   (body = the remaining query params, ksorted and http_build_query'ed).
 */
final class ZapbotSignature
{
    /** Default tolerance for the signature timestamp, in seconds. */
    public const DEFAULT_TOLERANCE = 600;

    /**
     * Validate a signature against a raw body (webhook style).
     */
    public static function isValid(string $signature, string $body, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): bool
    {
        $parts = static::parse($signature);

        if ($parts === null || abs(time() - $parts['timestamp']) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $parts['timestamp'] . '.' . $body, $secret);

        return hash_equals($expected, $parts['hmac']);
    }

    /**
     * Validate the signed query params of a hosted page return redirect.
     *
     * Pass the full query array (e.g. $request->query()); the `sig` param is
     * extracted and the remaining params are ksorted and re-encoded exactly
     * like Zapbot signs them.
     */
    public static function isValidQuery(array $query, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): bool
    {
        $signature = $query['sig'] ?? null;

        if (!is_string($signature)) {
            return false;
        }

        unset($query['sig']);
        ksort($query);

        return static::isValid($signature, http_build_query($query), $secret, $tolerance);
    }

    /**
     * Parse a `t={timestamp},v1={hmac}` signature.
     *
     * @return array{timestamp: int, hmac: string}|null
     */
    protected static function parse(string $signature): ?array
    {
        if (preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $signature, $matches) !== 1) {
            return null;
        }

        return ['timestamp' => (int) $matches[1], 'hmac' => $matches[2]];
    }
}
