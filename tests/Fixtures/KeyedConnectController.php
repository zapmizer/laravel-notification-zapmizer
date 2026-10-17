<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Eloquent\Model;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;

/**
 * A subclass using the seams step 7 fills: a key per connection and a
 * webhook URL carrying it.
 */
class KeyedConnectController extends ConnectController
{
    protected function webhookKeyFor(Model&Connectable $connectable): ?string
    {
        return 'fixed-key';
    }

    protected function webhookUrl(?string $key): string
    {
        return $key === null ? parent::webhookUrl($key) : 'https://app.test/hook/' . $key;
    }
}
