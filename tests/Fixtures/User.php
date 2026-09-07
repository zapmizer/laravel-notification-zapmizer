<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp as MustVerifyWhatsappContract;
use NotificationChannels\Zapmizer\MustVerifyWhatsapp;

class User extends Authenticatable implements Connectable, MustVerifyWhatsappContract
{
    use ConnectsZapmizer, MustVerifyWhatsapp;

    protected $table = 'users';

    protected $guarded = [];
}
