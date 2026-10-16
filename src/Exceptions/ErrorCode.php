<?php

namespace NotificationChannels\Zapmizer\Exceptions;

final class ErrorCode
{
    public const ALREADY_SUBSCRIBED = 'already_subscribed';
    public const PAYMENT_INCOMPLETE = 'payment_incomplete';
    public const SUBSCRIPTION_REQUIRED = 'subscription_required';
    public const PLAN_LIMIT = 'plan_limit';
    public const NOT_A_PARTNER_CONNECTION = 'not_a_partner_connection';
    public const ORIGIN_NOT_ALLOWED = 'origin_not_allowed';
    public const MISSING_ABILITY = 'missing_ability';
    public const CONNECTION_WITHOUT_NUMBER = 'connection_without_number';
    public const NUMBER_UNAVAILABLE = 'number_unavailable';
    public const APPROVER_WITHOUT_ACCESS = 'approver_without_access';
    public const RECIPIENT_NOT_FOUND = 'recipient_not_found';
    public const ATTACHMENT_UNREACHABLE = 'attachment_unreachable';
    public const BOT_OFFLINE = 'bot_offline';
    public const NUMBER_BANNED = 'number_banned';
    public const WINDOW_CLOSED = 'window_closed';
    public const NEEDS_RECONNECT = 'needs_reconnect';
}
