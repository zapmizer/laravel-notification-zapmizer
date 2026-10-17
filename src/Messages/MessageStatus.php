<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * Class MessageStatus.
 *
 * The `message_status` scale of a sent message — the same numbers as the
 * `ack` of the `message_status` webhook. Compare by the number, never by
 * `message_status_name`: the name changes between the send answer and the
 * tracking endpoints. A value the API adds later still arrives as an int.
 *
 * Progress goes from `SENDING` (0) to `PLAYED` (4). Negative values are
 * either waiting (`WAITING`, `QUEUED`) or failed (`FAILED_BEFORE_SENDING`,
 * `FAILED`). `-4` and `-1` are final; `3` and `4` are too, but not every
 * contact sends read receipts.
 */
final class MessageStatus
{
    /** Failed before leaving Zapmizer (QR number only). */
    public const FAILED_BEFORE_SENDING = -4;

    /** Waiting to be sent. */
    public const WAITING = -3;

    /** In Zapmizer's send queue — what `POST /messages` answers. */
    public const QUEUED = -2;

    /** WhatsApp refused it. */
    public const FAILED = -1;

    public const SENDING = 0;

    /** Reached WhatsApp's server. */
    public const SENT = 1;

    /** Delivered to the contact's device. */
    public const DELIVERED = 2;

    public const READ = 3;

    /** Audio or video played. */
    public const PLAYED = 4;

    public static function isFailure(int $status): bool
    {
        return $status === self::FAILED || $status === self::FAILED_BEFORE_SENDING;
    }

    public static function isFinal(int $status): bool
    {
        return self::isFailure($status) || $status === self::READ || $status === self::PLAYED;
    }

    /**
     * Whether `$status` got at least as far as `$target` (`DELIVERED` is
     * reached by `READ`). A failure reaches nothing — check it with
     * `isFailure()`.
     */
    public static function reached(int $status, int $target): bool
    {
        return !self::isFailure($status) && !self::isFailure($target) && $status >= $target;
    }
}
