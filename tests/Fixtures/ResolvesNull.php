<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;

/**
 * A mis-configured resolver: returns null instead of throwing noConnectable().
 */
class ResolvesNull implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        /** @phpstan-ignore-next-line */
        return null;
    }
}
