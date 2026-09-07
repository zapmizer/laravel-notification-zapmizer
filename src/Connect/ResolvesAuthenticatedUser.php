<?php

namespace NotificationChannels\Zapmizer\Connect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

/**
 * Class ResolvesAuthenticatedUser.
 *
 * Default connectable resolver: the authenticated user owns the connection.
 */
final class ResolvesAuthenticatedUser implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        $user = $request->user();

        if (!$user instanceof Model) {
            throw ZapmizerConnectException::noConnectable('There is no authenticated user to connect.');
        }

        if (!$user instanceof Connectable) {
            throw ZapmizerConnectException::notConnectable($user);
        }

        return $user;
    }
}
