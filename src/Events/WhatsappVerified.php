<?php

namespace NotificationChannels\Zapmizer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Zapmizer\Models\WhatsappVerified as WhatsappVerifiedModel;

/**
 * Class WhatsappVerified.
 *
 * Fired when a webhook from Zapbot confirms a WhatsApp number. Listen to it
 * to react in your application (notify the user, unlock features, ...).
 */
class WhatsappVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(public WhatsappVerifiedModel $verification)
    {
    }
}
