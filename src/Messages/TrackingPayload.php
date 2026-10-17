<?php

namespace NotificationChannels\Zapmizer\Messages;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * @internal The reading rules shared by the tracking answers and links: `data` with
 *           an `id` and an integer `message_status` is the minimum; the rest
 *           falls back to null.
 */
final class TrackingPayload
{
    /**
     * @throws ZapmizerConnectException
     */
    public static function data(array $payload): array
    {
        if (!is_array($payload['data'] ?? null)) {
            throw ZapmizerConnectException::unexpectedResponse('tracking answer has no `data`');
        }

        return $payload['data'];
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function id(array $data): string
    {
        $id = $data['id'] ?? null;

        if (is_int($id)) {
            return (string) $id;
        }

        if (!is_string($id) || $id === '') {
            throw ZapmizerConnectException::unexpectedResponse('invalid id');
        }

        return $id;
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function status(array $data): int
    {
        $status = $data['message_status'] ?? null;

        if (!is_int($status)) {
            throw ZapmizerConnectException::unexpectedResponse('invalid message_status');
        }

        return $status;
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The kind (`sent-messages` or `meta-messages`) and the id of a
     * tracking `links.self`; null for any other link (the legacy
     * `/messages/{id}`, a meta id that is not numeric, `.`/`..`).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function link(?string $link): ?array
    {
        $path = $link === null || Payload::url($link, underscoreInHost: true) === null ? null : parse_url($link, PHP_URL_PATH);

        if (!is_string($path) || preg_match('#/(sent-messages|meta-messages)/([^/]+)\z#', $path, $match) !== 1) {
            return null;
        }

        $id = rawurldecode($match[2]);

        if (!self::isId($id) || ($match[1] === 'meta-messages' && !ctype_digit($id))) {
            return null;
        }

        return [$match[1], $id];
    }

    /**
     * An id that is safe as one path segment: not empty, not `.` nor `..`.
     */
    public static function isId(string $id): bool
    {
        return $id !== '' && $id !== '.' && $id !== '..';
    }

    public static function selfUrl(array $data): ?string
    {
        return Payload::url($data['links']['self'] ?? null, underscoreInHost: true);
    }
}
