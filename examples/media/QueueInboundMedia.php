<?php

namespace App\Listeners;

use App\Jobs\StoreInboundMedia;
use NotificationChannels\Zapmizer\Events\MessageReceived;

/**
 * Hands the media of an inbound WhatsApp message to a queued job.
 *
 * Register it for NotificationChannels\Zapmizer\Events\MessageReceived. It
 * does no HTTP: the webhook must answer right away, and the media may still
 * be downloading on Zapmizer's side, so the waiting belongs in the queue
 * (see App\Jobs\StoreInboundMedia).
 */
class QueueInboundMedia
{
    public function handle(MessageReceived $event): void
    {
        // No connection means a single-tenant delivery: there is no token to ask with.
        if (!$event->message->hasMedia || $event->connection === null) {
            return;
        }

        StoreInboundMedia::dispatch($event->connection, $event->message);
    }
}
