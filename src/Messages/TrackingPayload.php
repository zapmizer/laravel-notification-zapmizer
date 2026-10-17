<?php

namespace NotificationChannels\Zapmizer\Messages;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * @internal The reading rules shared by the tracking answers: `data` with
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

    public static function selfUrl(array $data): ?string
    {
        return Payload::url($data['links']['self'] ?? null, underscoreInHost: true);
    }
}
