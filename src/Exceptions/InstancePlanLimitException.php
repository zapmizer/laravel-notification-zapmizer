<?php

namespace NotificationChannels\Zapmizer\Exceptions;

/**
 * Class InstancePlanLimitException.
 *
 * 402 from Zapmizer when creating an instance: the team's plan over there
 * has no room for another one. The message comes from Zapmizer and is meant
 * for the user.
 */
final class InstancePlanLimitException extends ZapmizerConnectException
{
}
