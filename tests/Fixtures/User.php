<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp as MustVerifyWhatsappContract;
use NotificationChannels\Zapmizer\MustVerifyWhatsapp;

class User extends Authenticatable implements MustVerifyWhatsappContract
{
    use MustVerifyWhatsapp;

    protected $table = 'users';

    protected $guarded = [];
}
