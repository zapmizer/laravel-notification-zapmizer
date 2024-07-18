<?php

namespace NotificationChannels\Zapmizer;

use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Events\Dispatcher;

class ZapmizerChannel
{
    public function __construct(private readonly Dispatcher $dispatcher)
    {
    }

    /**
     * Send the given notification.
     *
     *
     * @throws \Notification\Zapmizer\Exceptions\CouldNotSendNotification
     */
    public function send(mixed $notifiable, Notification $notification): ?array
    {
        // @phpstan-ignore-next-line
        $message = $notification->toZapmizer($notifiable);

        $notifiable->routeNotificationFor('zapmizer', $notification);

        // Send notification to the $notifiable instance...

        return [
            'message' => $message,
            'recipient' => $notifiable,
        ];
    }
}
