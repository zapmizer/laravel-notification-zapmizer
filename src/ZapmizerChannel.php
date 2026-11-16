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
     * `toZapmizer()` may send by itself and return the `SentMessage` (or
     * anything else, null included), which goes along untouched. Or it may
     * return the `ZapmizerMessage` it built: the channel sends it, to the
     * notifiable's `routeNotificationFor('zapmizer')` when the message has
     * no `to` (nothing is sent without either). A builder that was already
     * sent is not sent again. Either way, `NotificationSent` gets the
     * `SentMessage` in `response['message']`.
     *
     * @throws \NotificationChannels\Zapmizer\Exceptions\ZapmizerException
     */
    public function send(mixed $notifiable, Notification $notification): ?array
    {
        // @phpstan-ignore-next-line
        $message = $notification->toZapmizer($notifiable);

        $route = $notifiable->routeNotificationFor('zapmizer', $notification);

        if ($message instanceof ZapmizerMessage) {
            $message = $message->sentMessage() ?? $this->sendBuilder($message, $route);
        }

        return [
            'message' => $message,
            'recipient' => $notifiable,
        ];
    }

    /**
     * @return Messages\SentMessage|null null when the message has no `to`
     *                                   and the notifiable no route
     *
     * @throws \NotificationChannels\Zapmizer\Exceptions\ZapmizerException
     */
    protected function sendBuilder(ZapmizerMessage $message, mixed $route): ?Messages\SentMessage
    {
        if (blank($message->params['to'] ?? null)) {
            if (!is_scalar($route) || blank($route)) {
                return null;
            }

            $message->params['to'] = (string) $route;
        }

        return $message->send();
    }
}
