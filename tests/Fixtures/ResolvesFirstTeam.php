<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;

/**
 * Stand-in for an app resolver that connects the user's current team.
 */
class ResolvesFirstTeam implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        return Team::firstOrCreate(['name' => 'Acme']);
    }
}
