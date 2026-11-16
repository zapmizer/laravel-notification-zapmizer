<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;

/**
 * Class NumberConnected.
 *
 * Fired when the connect callback stores the paired number on the model's
 * connection (popup status `ok` or `webhook_failed`). A listener that throws
 * is reported and does not change the popup's status.
 */
class NumberConnected
{
    use Dispatchable, SerializesModels;

    public function __construct(public Model&Connectable $connectable, public ZapmizerConnection $connection)
    {
    }
}
