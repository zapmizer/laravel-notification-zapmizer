<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;

class ResolvesExternalIdTeam implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        return ExternalIdTeam::firstOrCreate(['name' => 'Acme']);
    }
}
