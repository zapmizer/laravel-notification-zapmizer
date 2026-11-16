<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\Messages\Message;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Webhook\Envelope;

/**
 * Class MessageSent.
 *
 * The number sent a message: the `message_sent` webhook event, only in a
 * webhook in version `2026-10-20` (Zapmizer 1.148.17 or later). It fires for
 * everything the number sends — through the API (the application's own
 * notifications included), the bot, or the phone. `$message->fromMe` is
 * always true; `$message->fromMeOtherDevice` tells what left from another
 * device, such as the phone, apart from what the API and the bot sent (in a
 * group it is always false).
 *
 * Same arguments as `MessageReceived`. A webhook in `2024-02-27` never fires
 * it: its `message` event only carries what the number received.
 */
class MessageSent
{
    use Dispatchable, SerializesModels;

    /**
     * @param array $payload The decoded webhook payload.
     */
    public function __construct(
        public Message $message,
        public ?ZapmizerConnection $connection,
        public array $payload,
        public ?Envelope $envelope = null,
    ) {
    }
}
