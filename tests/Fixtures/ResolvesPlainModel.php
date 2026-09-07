<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;

/**
 * A mis-configured resolver: hands back a model that is not Connectable.
 */
class ResolvesPlainModel implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        /** @phpstan-ignore-next-line */
        return PlainModel::firstOrCreate(['name' => 'Plain']);
    }
}
