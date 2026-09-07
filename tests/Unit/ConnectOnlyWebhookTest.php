<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Test\TestCase;

/**
 * An application on the connect flow only: `zapmizer_connections` published,
 * the verify migration never. `verify_number.*` is accepted unsigned, so a
 * forged delivery must be acknowledged — not a 500 on a missing table.
 */
class ConnectOnlyWebhookTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $migration = include __DIR__ . '/../../database/migrations/create_zapmizer_connections_table.php.stub';
        $migration->up();
    }

    protected function deliver(string $name): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/zapmizer/webhook', ['name' => $name, 'data' => ['number' => '5511999999999', 'from' => '5581999999999']]);
    }

    public function testVerifyEventsAreAcknowledgedWithoutTheVerifyTable()
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class]);
        $this->assertFalse(Schema::hasTable('whatsapp_verifieds'));

        foreach (['verify_number.verified', 'verify_number.failed'] as $name) {
            $this->deliver($name)->assertOk()->assertSeeText('Webhook Received');
        }

        Event::assertDispatchedTimes(WebhookReceived::class, 2);
        Event::assertDispatchedTimes(WebhookHandled::class, 2);
    }
}
