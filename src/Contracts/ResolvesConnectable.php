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
 * `ZapmizerConnectException::noConnectable()`: the package's JSON endpoints
 * answer 403 with the code `no_connectable` and the popup callback a result
 * page. Do not return null nor a model without Connectable: the package
 * routes answer that return-type failure with `no_connectable` too, with a
 * warning naming the resolver, but it is a setup defect, not an API.
 */
interface ResolvesConnectable
{
    /**
     * @throws \NotificationChannels\Zapmizer\Exceptions\NoConnectableException
     */
    public function resolve(Request $request): Model&Connectable;
}
