<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

/**
 * An app resolver with nothing to connect — a user without a team.
 */
class ResolvesNothing implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        throw ZapmizerConnectException::noConnectable('The user has no team.');
    }
}
