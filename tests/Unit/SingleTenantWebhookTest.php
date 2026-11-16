<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\MessageSent;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
                && $event->message->chatPhone() === '5581999998888'
                && $event->message->text === 'hello';
        });
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->connection === null);
    }

    /** @dataProvider messageContractEvents */
    #[DataProvider('messageContractEvents')]
    public function testAMessageOfTheContractFiresItsEventOnTheApplicationSecret(string $name, string $event)
    {
        Log::spy();
        Event::fake([MessageReceived::class, MessageSent::class]);
        $envelope = $this->contractEnvelope($name, ['id' => 'AAA', 'text' => 'oi']);

        $this->deliverSigned($envelope, 'app-secret')
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        Event::assertDispatched($event, fn ($dispatched) => $dispatched->connection === null
            && $dispatched->message->id === 'AAA'
            && $dispatched->message->text === 'oi'
            && $dispatched->envelope->version === '2026-10-20'
            && $dispatched->payload === $envelope);
        Event::assertNotDispatched($event === MessageSent::class ? MessageReceived::class : MessageSent::class);
        Log::shouldNotHaveReceived('warning');
    }

    public static function messageContractEvents(): array
    {
        return ['message_received' => ['message_received', MessageReceived::class], 'message_sent' => ['message_sent', MessageSent::class]];
    }

    public function testTheMessageEventDoesNotWarnAboutTheContract()
    {
        Log::spy();

        $this->deliverSigned($this->messageEnvelope(), 'app-secret')->assertOk();

        Log::shouldNotHaveReceived('warning');
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
