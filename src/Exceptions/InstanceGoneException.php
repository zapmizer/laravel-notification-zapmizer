<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use NotificationChannels\Zapmizer\Support\ApiError;

/**
 * Class InstanceGoneException.
 *
 * 404 when fetching an instance (deleted/unknown on Zapmizer). The stored
 * instance id is NOT cleared because of it: restarting the wizard
 * re-resolves the instance.
 */
final class InstanceGoneException extends ZapmizerApiException
{
    public function __construct(ApiError $error, int $instanceId)
    {
        parent::__construct($error, "Instance {$instanceId} is gone on Zapmizer.");
    }
}
