<?php

namespace NotificationChannels\Zapmizer\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class WebhookEvent.
 *
 * One row per webhook received from Zapbot. The unique `event_id` is the
 * idempotency key — a redelivery hits the existing row and is acknowledged
 * without reapplying the effect — and the table doubles as an audit trail
 * of everything that arrived. Extend it and point
 * `zapmizer.models.webhook_event` at your subclass to customize.
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
