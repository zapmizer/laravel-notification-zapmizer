<?php

namespace NotificationChannels\Zapmizer\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class WebhookEvent.
 *
 * One row per webhook received from Zapmizer — an audit trail of everything
 * that arrived (payload included) and whether a handler applied it. Extend
 * it and point `zapmizer.models.webhook_event` at your subclass to
 * customize.
 */
class WebhookEvent extends Model
{
    protected $table = 'zapmizer_webhook_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'handled' => 'boolean',
    ];
}
