<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Notifications\Notification;
use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\ZapmizerMessage;

/**
 * The notification of docs/envio.md: `toZapmizer()` sends and returns what
 * `send()` answered.
 */
class SendsThroughZapmizer extends Notification
{
    public function via(object $notifiable): array
    {
        return ['zapmizer'];
    }

    public function toZapmizer(object $notifiable): SentMessage
    {
        return ZapmizerMessage::create(from: '5581911110000', to: $notifiable->routeNotificationFor('zapmizer'))->text('Hello')->send();
    }
}
