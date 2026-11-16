<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;

/**
 * A resolver with a bug of its own: a TypeError that is not its return type.
 */
class ResolvesWithABug implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        /** @phpstan-ignore-next-line */
        return $this->team(null);
    }

    private function team(Request $request): Model&Connectable
    {
        return $request->user();
    }
}
