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
 */
interface ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable;
}
