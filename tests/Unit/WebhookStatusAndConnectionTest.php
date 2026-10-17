<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Event;
use NotificationChannels\Zapmizer\Events\ConnectionStateChanged;
use NotificationChannels\Zapmizer\Events\MessageStatusUpdated;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Fixtures\BuildsCatalogDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Webhook\ConnectionState;
use NotificationChannels\Zapmizer\Webhook\MessageStatus;
use PHPUnit\Framework\Attributes\DataProvider;

class WebhookStatusAndConnectionTest extends TestCase
{
    use AssertsContract, BuildsCatalogDeliveries, CreatesConnectionTables, SignsWebhookDeliveries;

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.webhook.secret', 'app-secret');
    }

    public function testTheStatusFixtureMatchesTheContract()
    {
        $this->assertMatchesSchema(self::catalogSchema('message_status'), $this->catalogEnvelope('message_status'));
        $this->assertMatchesSchema(self::catalogSchema('connection'), $this->catalogEnvelope('connection'));
    }

    public function testAStatusIsDispatchedWithTheEnvelopeAndTheConnection()
    {
        Event::fake([MessageStatusUpdated::class, WebhookReceived::class, WebhookHandled::class]);
        $connection = $this->connectedTeam('secret-a')->zapmizerConnection;
        $envelope = $this->catalogEnvelope('message_status');

        $this->deliverSigned($envelope, 'secret-a')
            ->assertOk()
            ->assertSeeText('Webhook Received');

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
        Event::assertDispatched(MessageStatusUpdated::class, function (MessageStatusUpdated $event) use ($connection, $envelope) {
            $status = $event->status;

            return $status instanceof MessageStatus
                && $status->wappMessageId === '3EB0C767D82B6A6C7A2F'
                && $status->to === '5581999998888@c.us'
                && $status->ack === MessageStatus::ACK_DELIVERED
                && $status->status === 'delivered'
                && $status->occurredAt?->getTimestamp() === 1760659200
                && $status->raw === $envelope['data']
                && !$status->isFailed()
                && $status->reached(MessageStatus::ACK_SENT)
                && !$status->reached(MessageStatus::ACK_READ)
                && $event->envelope->id === $envelope['id']
                && $event->envelope->name === 'message_status'
                && $event->envelope->wid === '5581911110000@c.us'
                && $event->envelope->createdAt?->toIso8601String() === '2025-10-17T12:00:00+00:00'
                && $event->connection->is($connection)
                && $event->payload === $envelope;
        });
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertNotDispatched(WebhookHandled::class);
    }

    public function testTheSingleTenantSecretGivesNoConnection()
    {
        Event::fake([MessageStatusUpdated::class]);

        $this->deliverSigned($this->catalogEnvelope('message_status'), 'app-secret')->assertOk();

        Event::assertDispatched(MessageStatusUpdated::class, fn (MessageStatusUpdated $event) => $event->connection === null);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function ackScale(): array
    {
        return [
            'failed' => [-1, 'failed'],
            'pending' => [0, 'pending'],
            'sent' => [1, 'sent'],
            'delivered' => [2, 'delivered'],
            'read' => [3, 'read'],
            'played' => [4, 'played'],
        ];
    }

    /** @dataProvider ackScale */
    #[DataProvider('ackScale')]
    public function testEveryAckOfTheScaleIsDelivered(int $ack, string $name)
    {
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');
        $envelope = $this->catalogEnvelope('message_status', ['ack' => $ack, 'status' => $name] + self::catalogData()['message_status']);
        $this->assertMatchesSchema(self::catalogSchema('message_status'), $envelope);

        $this->deliverSigned($envelope, 'secret-a')->assertOk();

        Event::assertDispatched(MessageStatusUpdated::class, fn (MessageStatusUpdated $event) => $event->status->ack === $ack
            && $event->status->status === $name
            && $event->status->isFailed() === ($ack === -1));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function statusesThatAreNotDelivered(): array
    {
        return [
            'unknown status' => [['ack' => 2, 'status' => 'unknown']],
            'ack out of the scale' => [['ack' => 5, 'status' => 'delivered']],
            'ack -4 (only on the tracking)' => [['ack' => -4, 'status' => 'failed']],
            'ack as a string' => [['ack' => '2']],
            'ack missing' => [['ack' => null]],
            'wapp_message_id missing' => [['wapp_message_id' => null]],
            'wapp_message_id empty' => [['wapp_message_id' => '']],
            'wapp_message_id as an int' => [['wapp_message_id' => 123]],
        ];
    }

    /** @dataProvider statusesThatAreNotDelivered */
    #[DataProvider('statusesThatAreNotDelivered')]
    public function testAStatusThatCannotBeReadDispatchesOnlyWebhookReceived(array $overrides)
    {
        Event::fake([MessageStatusUpdated::class, WebhookReceived::class]);
        $this->connectedTeam('secret-a');
        $data = array_filter($overrides + self::catalogData()['message_status'], fn ($value) => $value !== null);

        $this->deliverSigned($this->catalogEnvelope('message_status', $data), 'secret-a')
            ->assertOk()
            ->assertSeeText('Webhook Received');

        Event::assertNotDispatched(MessageStatusUpdated::class);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    public function testTheStatusNameFollowsTheAckWhenItIsMissingOrNotAName()
    {
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('message_status', ['wapp_message_id' => 'A', 'ack' => 3]), 'secret-a')->assertOk();
        $this->deliverSigned($this->catalogEnvelope('message_status', ['wapp_message_id' => 'B', 'ack' => 1, 'status' => 42]), 'secret-a')->assertOk();

        Event::assertDispatched(MessageStatusUpdated::class, fn (MessageStatusUpdated $event) => $event->status->wappMessageId === 'A'
            && $event->status->status === 'read'
            && $event->status->to === null
            && $event->status->occurredAt === null);
        Event::assertDispatched(MessageStatusUpdated::class, fn (MessageStatusUpdated $event) => $event->status->wappMessageId === 'B'
            && $event->status->status === 'sent');
    }

    public function testTheAckWinsOverADisagreeingName()
    {
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('message_status', ['wapp_message_id' => 'A', 'ack' => 3, 'status' => 'sent']), 'secret-a')->assertOk();

        Event::assertDispatched(MessageStatusUpdated::class, fn (MessageStatusUpdated $event) => $event->status->ack === 3
            && $event->status->status === 'read');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string|null, 3: string|null}>
     */
    public static function connectionStates(): array
    {
        return [
            'qr_pending' => [['state' => 'qr_pending', 'reason' => null, 'kind' => null], 'qr_pending', null, null],
            'connecting' => [['state' => 'connecting'], 'connecting', null, null],
            'connected' => [['state' => 'connected', 'reason' => null, 'kind' => null], 'connected', null, null],
            'banned' => [['state' => 'disconnected', 'reason' => 'BANNED', 'kind' => 'banned'], 'disconnected', 'BANNED', 'banned'],
            'logged_out' => [['state' => 'disconnected', 'reason' => 'LOGOUT', 'kind' => 'logged_out'], 'disconnected', 'LOGOUT', 'logged_out'],
            'conflict' => [['state' => 'disconnected', 'reason' => 'CONFLICT', 'kind' => 'conflict'], 'disconnected', 'CONFLICT', 'conflict'],
            'connection_lost' => [['state' => 'disconnected', 'reason' => 'NAVIGATION', 'kind' => 'connection_lost'], 'disconnected', 'NAVIGATION', 'connection_lost'],
            'disconnected without kind' => [['state' => 'disconnected', 'reason' => null, 'kind' => null], 'disconnected', null, null],
        ];
    }

    /** @dataProvider connectionStates */
    #[DataProvider('connectionStates')]
    public function testEveryConnectionStateIsDelivered(array $data, string $state, ?string $reason, ?string $kind)
    {
        Event::fake([ConnectionStateChanged::class, WebhookHandled::class]);
        $connection = $this->connectedTeam('secret-a')->zapmizerConnection;
        $envelope = $this->catalogEnvelope('connection', $data);
        $this->assertMatchesSchema(self::catalogSchema('connection'), $envelope);

        $this->deliverSigned($envelope, 'secret-a')->assertOk()->assertSeeText('Webhook Received');

        Event::assertDispatched(ConnectionStateChanged::class, fn (ConnectionStateChanged $event) => $event->state instanceof ConnectionState
            && $event->state->state === $state
            && $event->state->reason === $reason
            && $event->state->kind === $kind
            && $event->state->isConnected() === ($state === 'connected')
            && $event->state->isDisconnected() === ($state === 'disconnected')
            && $event->isAttributable()
            && $event->envelope->id === $envelope['id']
            && $event->connection->is($connection));
        Event::assertNotDispatched(WebhookHandled::class);
    }

    public function testAConnectionWithoutWidIsDeliveredAsNotAttributable()
    {
        Event::fake([ConnectionStateChanged::class]);
        $this->connectedTeam('secret-a');
        $envelope = $this->catalogEnvelope('connection', ['state' => 'qr_pending', 'reason' => null, 'kind' => null], ['wid' => null]);
        $this->assertMatchesSchema(self::catalogSchema('connection'), $envelope);

        $this->deliverSigned($envelope, 'secret-a', wid: null)->assertOk();

        Event::assertDispatched(ConnectionStateChanged::class, fn (ConnectionStateChanged $event) => $event->envelope->wid === null
            && !$event->isAttributable());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function connectionDataThatCannotBeRead(): array
    {
        return [
            'the old shape (status)' => [['status' => 'qr_pending']],
            'unknown state' => [['state' => 'off']],
            'state as an int' => [['state' => 1]],
            'data is a list' => [[['state' => 'connected']]],
            'data is a string' => ['connected'],
        ];
    }

    /** @dataProvider connectionDataThatCannotBeRead */
    #[DataProvider('connectionDataThatCannotBeRead')]
    public function testAConnectionThatCannotBeReadDispatchesOnlyWebhookReceived(mixed $data)
    {
        Event::fake([ConnectionStateChanged::class, WebhookReceived::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned(['data' => $data] + $this->catalogEnvelope('connection'), 'secret-a')
            ->assertOk()
            ->assertSeeText('Webhook Received');

        Event::assertNotDispatched(ConnectionStateChanged::class);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    public function testSecondaryFieldsOfTheWrongTypeAreNull()
    {
        Event::fake([ConnectionStateChanged::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('connection', ['state' => 'disconnected', 'reason' => 7, 'kind' => ['banned']], [
            'created_at' => 'not a date',
            'id' => 99,
        ]), 'secret-a')->assertOk();

        Event::assertDispatched(ConnectionStateChanged::class, fn (ConnectionStateChanged $event) => $event->state->reason === null
            && $event->state->kind === null
            && $event->envelope->createdAt === null
            && $event->envelope->id === null);
    }

    public function testAKindNewToThePackageIsKept()
    {
        Event::fake([ConnectionStateChanged::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('connection', ['state' => 'disconnected', 'kind' => 'restricted']), 'secret-a')->assertOk();

        Event::assertDispatched(ConnectionStateChanged::class, fn (ConnectionStateChanged $event) => $event->state->kind === 'restricted');
    }
}
