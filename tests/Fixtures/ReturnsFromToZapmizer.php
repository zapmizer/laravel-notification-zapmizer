<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Closure;
use Illuminate\Notifications\Notification;

/**
 * A notification whose `toZapmizer()` returns whatever the closure builds:
 * an unsent builder, a builder already sent, a SentMessage or null.
 */
class ReturnsFromToZapmizer extends Notification
{
    public function __construct(private Closure $build)
    {
    }

    public function via(object $notifiable): array
    {
        return ['zapmizer'];
    }

    public function toZapmizer(object $notifiable): mixed
    {
        return ($this->build)($notifiable);
    }
}
