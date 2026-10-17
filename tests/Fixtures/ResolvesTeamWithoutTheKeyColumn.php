<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;

/**
 * An app resolver that loads the team's connection with its own select,
 * one that leaves `webhook_key` out.
 */
class ResolvesTeamWithoutTheKeyColumn implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        $team = Team::firstOrCreate(['name' => 'Acme']);
        $columns = array_values(array_diff(Schema::getColumnListing('zapmizer_connections'), ['webhook_key']));

        $team->setRelation('zapmizerConnection', $team->zapmizerConnection()->select($columns)->first());

        return $team;
    }
}
