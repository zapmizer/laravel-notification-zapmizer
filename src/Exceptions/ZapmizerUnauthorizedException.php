<?php

namespace NotificationChannels\Zapmizer\Exceptions;

/**
 * Class ZapmizerUnauthorizedException.
 *
 * 401 from Zapmizer: the stored team token was revoked on the other side.
 * Whoever catches it decides the response (`reauth_required`) — the
 * connection is never deactivated because of it.
 */
final class ZapmizerUnauthorizedException extends ZapmizerConnectException
{
}
