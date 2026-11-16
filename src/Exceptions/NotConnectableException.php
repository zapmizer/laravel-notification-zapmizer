<?php

namespace NotificationChannels\Zapmizer\Exceptions;

/**
 * Class NotConnectableException.
 *
 * The resolver handed back a model that does not implement
 * `Contracts\Connectable` (the default resolver with a user model without
 * the trait): a setup defect, not a user error. The connect, reconnect and
 * inbox routes answer it like NoConnectableException (403 `no_connectable`,
 * or the popup's `no_connectable`), with a warning in the log.
 */
final class NotConnectableException extends ZapmizerConnectException
{
}
