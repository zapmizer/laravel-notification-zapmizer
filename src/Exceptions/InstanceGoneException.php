<?php

namespace NotificationChannels\Zapmizer\Exceptions;

/**
 * Class InstanceGoneException.
 *
 * Non-401 4xx when fetching an instance (deleted/unknown on Zapmizer). The
 * stored instance id is NOT cleared because of it: restarting the wizard
 * re-resolves the instance.
 */
final class InstanceGoneException extends ZapmizerConnectException
{
}
