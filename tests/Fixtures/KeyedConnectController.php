<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use NotificationChannels\Zapmizer\Connect\ConnectToken;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;

/**
 * A subclass using the seams step 7 fills: a key per connection and a
 * webhook URL carrying it.
 */
class KeyedConnectController extends ConnectController
{
    /**
     * The pending webhook key `storeToken()` saw; reset by the test.
     */
    public static ?string $storedWith = null;

    protected function webhookKeyFor(Model&Connectable $connectable): ?string
    {
        return 'fixed-key';
    }

    protected function webhookUrl(?string $key): string
    {
        return $key === null ? parent::webhookUrl($key) : 'https://app.test/hook/' . $key;
    }

    protected function storeToken(Model&Connectable $connectable, ConnectToken $token): View
    {
        static::$storedWith = $this->pendingWebhookKey;

        return parent::storeToken($connectable, $token);
    }
}
