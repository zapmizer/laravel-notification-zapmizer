<?php

namespace NotificationChannels\Zapmizer\Exceptions;

/**
 * Class NoConnectableException.
 *
 * The resolver found nothing to connect on this request (no authenticated
 * user, a user without a team, ...). The connect routes answer 403 with the
 * code `no_connectable`; the popup callback reports it through the result
 * page.
 */
final class NoConnectableException extends ZapmizerConnectException
{
}
