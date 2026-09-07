<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;
use NotificationChannels\Zapmizer\Contracts\Connectable;

class Team extends Model implements Connectable
{
    use ConnectsZapmizer;

    protected $table = 'teams';

    protected $guarded = [];
}
