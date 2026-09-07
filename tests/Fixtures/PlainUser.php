<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An authenticatable WITHOUT the Connectable trait/contract.
 */
class PlainUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
