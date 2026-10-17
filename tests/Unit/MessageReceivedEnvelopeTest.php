<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Event;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Fixtures\BuildsCatalogDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Webhook\Envelope;

class MessageReceivedEnvelopeTest extends TestCase
{
    use AssertsContract, BuildsCatalogDeliveries, CreatesConnectionTables, SignsWebhookDeliveries;

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.webhook.secret', 'app-secret');
    }

    public function testMessageReceivedCarriesTheEnvelope()
    {
        Event::fake([MessageReceived::class]);
        $this->connectedTeam('secret-a');
        $envelope = $this->catalogEnvelope('message', $this->messageEnvelope()['data']);
        $this->assertMatchesSchema(self::catalogSchema('message'), $envelope);

        $this->deliverSigned($envelope, 'secret-a')->assertOk()->assertSeeText('Webhook Handled');

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->envelope instanceof Envelope
            && $event->envelope->id === $envelope['id']
            && $event->envelope->name === 'message'
            && $event->envelope->wid === '5581911110000@c.us'
            && $event->envelope->createdAt?->toIso8601String() === '2025-10-17T12:00:00+00:00');
    }

    public function testAnEnvelopeWithoutIdReadsAsNull()
    {
        Event::fake([MessageReceived::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope(), 'secret-a')->assertOk();

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->envelope->id === null
            && $event->envelope->createdAt === null
            && $event->envelope->wid === null);
    }

    public function testItStillBuildsWithTheThreeArgumentsOfBefore()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope());
        $event = new MessageReceived($message, null, ['name' => 'message']);

        $this->assertNull($event->envelope);
        $this->assertSame($message, $event->message);
    }

    public function testATimestampOutOfRangeFallsBackToNowEndToEnd()
    {
        // Same fallback as a timestamp that is not a number.
        Event::fake([MessageReceived::class]);
        $this->connectedTeam('secret-a');
        $this->travelTo(now()->startOfSecond());

        $this->deliverSigned($this->messageEnvelope(overrides: ['timestamp' => PHP_INT_MAX]), 'secret-a')
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->message->sentAt->equalTo(now()));
    }

    public function testATimestampOutOfRangeDoesNotThrowWhenBuildingByHand()
    {
        $this->travelTo(now()->startOfSecond());

        foreach ([PHP_INT_MAX, '99999999999999999999', -5] as $timestamp) {
            $message = InboundMessage::fromMessage(['timestamp' => $timestamp] + $this->messageEnvelope()['data'][0]);

            $this->assertTrue($message->sentAt->equalTo(now()), (string) $timestamp);
        }

        $this->assertSame(1760659200, InboundMessage::fromMessage(['timestamp' => 1760659200] + $this->messageEnvelope()['data'][0])->sentAt->getTimestamp());
    }
}
