<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;

/**
 * Class ReconnectRefused.
 *
 * Fired when Zapmizer refuses to reconnect the model's number with a 402
 * (`plan_limit`, `subscription_required`, ...). `$message` is Zapmizer's own
 * text, `null` when it sent none. A listener that throws is reported and
 * does not change the `refused` answer.
 */
class ReconnectRefused
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Model&Connectable $connectable,
        public ZapmizerConnection $connection,
        public string $code,
        public ?string $message,
    ) {
    }
}
