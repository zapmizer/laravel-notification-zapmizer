<?php

namespace NotificationChannels\Zapmizer\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Interface ResolvesConnectable.
 *
 * Tells the connect flow which model is being connected on a request. The
 * returned model implements Contracts\Connectable (through the trait of the
 * same name). Configure your implementation in `zapmizer.connect.resolver`
 * — e.g. return `$request->user()->currentTeam` for team-scoped connections.
 *
 * When there is nothing to connect (no user, a user without a team), throw
 * `ZapmizerConnectException::noConnectable()`: it renders 403 with the code
 * `no_connectable` on the JSON endpoints and a result page on the popup
 * callback. Returning null is not an option — the return type is the
 * contract, and a null would surface as a TypeError (500).
 */
interface ResolvesConnectable
{
    /**
     * @throws \NotificationChannels\Zapmizer\Exceptions\NoConnectableException
     */
    public function resolve(Request $request): Model&Connectable;
}
