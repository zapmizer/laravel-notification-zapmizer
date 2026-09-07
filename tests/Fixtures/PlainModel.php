<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model WITHOUT the Connectable trait/contract.
 */
class PlainModel extends Model
{
    protected $table = 'teams';

    protected $guarded = [];
}
