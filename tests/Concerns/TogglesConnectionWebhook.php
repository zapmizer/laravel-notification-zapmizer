<?php

namespace NotificationChannels\Zapmizer\Test\Concerns;

trait TogglesConnectionWebhook
{
    protected function addWebhookKeyColumn(): void
    {
        (include __DIR__ . '/../../database/migrations/add_webhook_key_to_zapmizer_connections_table.php.stub')->up();
    }
}
