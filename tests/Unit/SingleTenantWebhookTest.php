<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\TestCase;

/**
 * A single-tenant application: `ZAPMIZER_API_TOKEN` + `ZAPMIZER_WEBHOOK_SECRET`,
 * and the connections migration never published. The webhook must work on
 * the application secret alone — a missing table is not a 500.
 */
class SingleTenantWebhookTest extends TestCase
{
    use SignsWebhookDeliveries;

    protected function defineEnvironment($app)
    {
        $app['config']->set('zapmizer.api_token', 'single-tenant-token');
        $app['config']->set('zapmizer.webhook.secret', 'app-secret');
    }

    protected function defineDatabaseMigrations()
    {
        // Only what the verify-number flow needs — no zapmizer_connections.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->timestamps();
        });

        $migration = include __DIR__ . '/../../database/migrations/create_whatsapp_verifieds_table.php.stub';
        $migration->up();
    }

    public function testSignedMessageIsAcceptedOnTheApplicationSecretWithoutTheConnectionsTable()
    {
        Event::fake([MessageReceived::class, WebhookReceived::class]);
        $this->assertFalse(Schema::hasTable('zapmizer_connections'));

        $this->deliverSigned($this->messageEnvelope('5581999998888@c.us', ['id' => 'ABC', 'body' => 'hello']), 'app-secret')
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        Event::assertDispatched(MessageReceived::class, function (MessageReceived $event) {
            return $event->connection === null
                && $event->message->fromPhone === '5581999998888'
                && $event->message->body === 'hello';
        });
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->connection === null);
    }

    public function testWrongSecretIsRefusedWithoutTheConnectionsTable()
    {
        Log::spy();

        $this->deliverSigned($this->messageEnvelope(), 'not-the-secret')->assertStatus(401);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => $context['reason'] === 'no known secret signs this delivery'
        );
    }

    public function testUnsignedMessageIsRefusedWithoutTheConnectionsTable()
    {
        $this->deliverUnsigned($this->messageEnvelope())->assertStatus(401);
    }

    public function testUnsignedVerifyNumberStillPasses()
    {
        $this->deliverUnsigned(['name' => 'verify_number.verified', 'data' => ['number' => '5599888887777']])
            ->assertOk()
            ->assertSeeText('Webhook Received');
    }

    public function testNoSecretConfiguredRefusesEverySignedDelivery()
    {
        config()->set('zapmizer.webhook.secret', null);

        $this->deliverSigned($this->messageEnvelope(), 'app-secret')->assertStatus(401);
    }
}
