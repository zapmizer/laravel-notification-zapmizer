<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use NotificationChannels\Zapmizer\Events\CallReceived;
use NotificationChannels\Zapmizer\Events\ConnectionStateChanged;
use NotificationChannels\Zapmizer\Events\ContactNumberChanged;
use NotificationChannels\Zapmizer\Events\GroupAdminsChanged;
use NotificationChannels\Zapmizer\Events\GroupJoinRequested;
use NotificationChannels\Zapmizer\Events\GroupMembershipChanged;
use NotificationChannels\Zapmizer\Events\GroupUpdated;
use NotificationChannels\Zapmizer\Events\MessageEdited;
use NotificationChannels\Zapmizer\Events\MessageReacted;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\MessageRevoked;
use NotificationChannels\Zapmizer\Events\MessageStatusUpdated;
use NotificationChannels\Zapmizer\Events\MessageTranscribed;
use NotificationChannels\Zapmizer\Events\PollVoted;
use NotificationChannels\Zapmizer\Events\WebhookEvent;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Fixtures\BuildsCatalogDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\HandlesStatusWebhookController;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\WithoutCatalogEventsWebhookController;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class WebhookCatalogEventsTest extends TestCase
{
    use AssertsContract, BuildsCatalogDeliveries, CreatesConnectionTables, SignsWebhookDeliveries, TogglesConnectionWebhook;

    private const ALL = [
        MessageStatusUpdated::class, ConnectionStateChanged::class, MessageReacted::class, MessageEdited::class,
        MessageRevoked::class, MessageTranscribed::class, CallReceived::class, PollVoted::class,
        ContactNumberChanged::class, GroupMembershipChanged::class, GroupAdminsChanged::class,
        GroupJoinRequested::class, GroupUpdated::class,
    ];

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.webhook.secret', 'app-secret');
    }

    protected function defineRoutes($router)
    {
        $router->post('status-webhook', [HandlesStatusWebhookController::class, 'handleWebhook'])
            ->middleware(\NotificationChannels\Zapmizer\Http\Middleware\VerifyWebhookSignature::class);
        $router->post('quiet-webhook', [WithoutCatalogEventsWebhookController::class, 'handleWebhook'])
            ->middleware(\NotificationChannels\Zapmizer\Http\Middleware\VerifyWebhookSignature::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        HandlesStatusWebhookController::$handled = 0;
    }

    /**
     * The 11 other events: the class, its data property, the identifying
     * field(s), and what the default fixture reads as.
     *
     * @return array<string, array{0: string, 1: class-string<WebhookEvent>, 2: string, 3: array<int, string>, 4: array<string, mixed>}>
     */
    public static function catalog(): array
    {
        $chat = '5581999998888@c.us';
        $group = '120363000000000000@g.us';

        return [
            'message_reacted' => ['message_reacted', MessageReacted::class, 'reaction', ['wapp_message_id'], [
                'wappMessageId' => '3EB0C767D82B6A6C7A2F', 'chatWid' => $chat, 'from' => $chat, 'emoji' => '👍', 'removed' => false,
            ]],
            'message_edited' => ['message_edited', MessageEdited::class, 'edit', ['wapp_message_id'], [
                'wappMessageId' => '3EB0C767D82B6A6C7A2F', 'chatWid' => $chat, 'fromMe' => false, 'body' => 'new text', 'previousBody' => 'old text',
            ]],
            'message_revoked' => ['message_revoked', MessageRevoked::class, 'revocation', ['wapp_message_id'], [
                'wappMessageId' => '3EB0C767D82B6A6C7A2F', 'chatWid' => $chat, 'fromMe' => false, 'scope' => 'everyone',
            ]],
            'message_transcribed' => ['message_transcribed', MessageTranscribed::class, 'transcription', ['wapp_message_id'], [
                'wappMessageId' => '3EB0C767D82B6A6C7A2F', 'chatWid' => $chat, 'text' => 'hello there', 'durationSeconds' => 4.5, 'model' => 'whisper-1', 'source' => 'auto',
            ]],
            'incoming_call' => ['incoming_call', CallReceived::class, 'call', ['call_id'], [
                'callId' => 'CALL1234', 'from' => $chat, 'isVideo' => true, 'isGroup' => false, 'fromMe' => false,
            ]],
            'poll_vote' => ['poll_vote', PollVoted::class, 'vote', ['wapp_message_id'], [
                'wappMessageId' => '3EB0C767D82B6A6C7A2F', 'chatWid' => $chat, 'voter' => $chat, 'selectedOptions' => ['Yes', 'Maybe'],
            ]],
            'contact_number_changed' => ['contact_number_changed', ContactNumberChanged::class, 'change', ['old_wid', 'new_wid'], [
                'oldWid' => $chat, 'newWid' => '5581977776666@c.us', 'isContact' => true,
            ]],
            'group_membership' => ['group_membership', GroupMembershipChanged::class, 'membership', ['group_wid'], [
                'groupWid' => $group, 'kind' => 'removed', 'by' => $chat,
            ]],
            'group_admins_changed' => ['group_admins_changed', GroupAdminsChanged::class, 'change', ['group_wid'], [
                'groupWid' => $group, 'kind' => 'promoted', 'participants' => [$chat, '5581977776666@c.us'], 'by' => '5581955554444@c.us',
            ]],
            'group_join_request' => ['group_join_request', GroupJoinRequested::class, 'joinRequest', ['group_wid'], [
                'groupWid' => $group, 'requester' => $chat, 'participants' => [$chat],
            ]],
            'group_updated' => ['group_updated', GroupUpdated::class, 'update', ['group_wid'], [
                'groupWid' => $group, 'change' => 'subject', 'body' => 'New group name', 'by' => $chat,
            ]],
        ];
    }

    /** @dataProvider catalog */
    #[DataProvider('catalog')]
    public function testEachEventIsDispatchedWithItsFields(string $name, string $class, string $property, array $identifiers, array $expected)
    {
        Event::fake([...self::ALL, WebhookReceived::class, WebhookHandled::class, MessageReceived::class]);
        $connection = $this->connectedTeam('secret-a')->zapmizerConnection;
        $envelope = $this->catalogEnvelope($name);
        $this->assertMatchesSchema(self::catalogSchema($name), $envelope);

        $this->deliverSigned($envelope, 'secret-a')
            ->assertOk()
            ->assertSeeText('Webhook Received');

        Event::assertDispatchedTimes($class, 1);
        Event::assertDispatched($class, function (WebhookEvent $event) use ($property, $expected, $envelope, $connection) {
            $data = $event->{$property};

            foreach ($expected as $field => $value) {
                $this->assertSame($value, $data->{$field}, $field);
            }

            $this->assertSame(1760659200, $data->occurredAt?->getTimestamp());
            $this->assertSame($envelope['data'], $data->raw);
            $this->assertSame($envelope['id'], $event->envelope->id);
            $this->assertSame($envelope['name'], $event->envelope->name);
            $this->assertTrue($event->connection->is($connection));
            $this->assertSame($envelope, $event->payload);

            return true;
        });

        foreach (array_diff(self::ALL, [$class]) as $other) {
            Event::assertNotDispatched($other);
        }

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertNotDispatched(WebhookHandled::class);
        Event::assertNotDispatched(MessageReceived::class);
    }

    /** @dataProvider catalog */
    #[DataProvider('catalog')]
    public function testAMissingOrEmptyIdentifierDispatchesNoTypedEvent(string $name, string $class, string $property, array $identifiers)
    {
        Event::fake([$class, WebhookReceived::class]);
        $this->connectedTeam('secret-a');

        foreach ($identifiers as $identifier) {
            foreach ([null, '', 42] as $value) {
                $data = self::catalogData()[$name];
                $data[$identifier] = $value;

                if ($value === null) {
                    unset($data[$identifier]);
                }

                $this->deliverSigned($this->catalogEnvelope($name, $data), 'secret-a')
                    ->assertOk()
                    ->assertSeeText('Webhook Received');
            }
        }

        Event::assertNotDispatched($class);
        Event::assertDispatchedTimes(WebhookReceived::class, 3 * count($identifiers));
    }

    /** @dataProvider catalog */
    #[DataProvider('catalog')]
    public function testOnlyTheIdentifierIsNeeded(string $name, string $class, string $property, array $identifiers)
    {
        Event::fake([$class]);
        $this->connectedTeam('secret-a');
        $data = array_intersect_key(self::catalogData()[$name], array_flip($identifiers));

        $this->deliverSigned($this->catalogEnvelope($name, $data), 'secret-a')->assertOk();

        Event::assertDispatched($class, function (WebhookEvent $event) use ($property) {
            $this->assertNull($event->{$property}->occurredAt);

            return true;
        });
    }

    /** @dataProvider catalog */
    #[DataProvider('catalog')]
    public function testSecondaryFieldsOfTheWrongTypeReadAsAbsent(string $name, string $class, string $property, array $identifiers, array $expected)
    {
        Event::fake([$class]);
        $this->connectedTeam('secret-a');
        $data = self::catalogData()[$name];

        foreach (array_diff(array_keys($data), $identifiers) as $field) {
            $data[$field] = ['wrong' => 'type'];
        }

        $this->deliverSigned($this->catalogEnvelope($name, $data), 'secret-a')->assertOk();

        Event::assertDispatched($class, function (WebhookEvent $event) use ($property, $expected) {
            $dto = $event->{$property};

            foreach ($expected as $field => $value) {
                if (is_bool($value)) {
                    $this->assertFalse($dto->{$field}, $field);
                } elseif (is_array($value)) {
                    $this->assertSame([], $dto->{$field}, $field);
                } elseif (!in_array($field, ['wappMessageId', 'callId', 'oldWid', 'newWid', 'groupWid'], true)) {
                    $this->assertContains($dto->{$field}, [null, ''], $field);
                }
            }

            return true;
        });
    }

    public function testAListOfStringsKeepsOnlyTheStrings()
    {
        Event::fake([PollVoted::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('poll_vote', ['wapp_message_id' => 'P', 'selected_options' => ['Yes', 3, null, 'No']]), 'secret-a')->assertOk();

        Event::assertDispatched(PollVoted::class, fn (PollVoted $event) => $event->vote->selectedOptions === ['Yes', 'No']);
    }

    public function testARemovedReactionHasAnEmptyEmoji()
    {
        Event::fake([MessageReacted::class]);
        $this->connectedTeam('secret-a');
        $envelope = $this->catalogEnvelope('message_reacted', ['emoji' => '', 'removed' => true] + self::catalogData()['message_reacted']);
        $this->assertMatchesSchema(self::catalogSchema('message_reacted'), $envelope);

        $this->deliverSigned($envelope, 'secret-a')->assertOk();

        Event::assertDispatched(MessageReacted::class, fn (MessageReacted $event) => $event->reaction->emoji === '' && $event->reaction->removed);
    }

    public function testATranscriptionDurationMayBeAnInteger()
    {
        Event::fake([MessageTranscribed::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('message_transcribed', ['duration_seconds' => 7, 'source' => null] + self::catalogData()['message_transcribed']), 'secret-a')->assertOk();

        Event::assertDispatched(MessageTranscribed::class, fn (MessageTranscribed $event) => $event->transcription->durationSeconds === 7.0
            && $event->transcription->source === null);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function namesOutsideTheCatalog(): array
    {
        return [
            'qr' => ['qr'],
            'unknown' => ['message_deleted_forever'],
            'an int' => [7],
            'missing' => [null],
        ];
    }

    /** @dataProvider namesOutsideTheCatalog */
    #[DataProvider('namesOutsideTheCatalog')]
    public function testANameOutsideTheCatalogFiresNothingNew(mixed $name)
    {
        Event::fake([...self::ALL, WebhookReceived::class]);
        $this->connectedTeam('secret-a');
        $envelope = ['name' => $name] + $this->catalogEnvelope('message_status');

        if ($name === null) {
            unset($envelope['name']);
        }

        $this->deliverSigned($envelope, 'secret-a')->assertOk()->assertSeeText('Webhook Received');

        foreach (self::ALL as $class) {
            Event::assertNotDispatched($class);
        }

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    public function testAMessageFiresNoTypedEvent()
    {
        Event::fake([...self::ALL, MessageReceived::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope(), 'secret-a')->assertOk()->assertSeeText('Webhook Handled');

        Event::assertDispatchedTimes(MessageReceived::class, 1);

        foreach (self::ALL as $class) {
            Event::assertNotDispatched($class);
        }
    }

    public function testAnApplicationHandlerOfTheSameEventKeepsRunningAndTheEventStillGoesOut()
    {
        Event::fake([MessageStatusUpdated::class, WebhookHandled::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('message_status'), 'secret-a', path: '/status-webhook')
            ->assertStatus(202)
            ->assertSeeText('App Handled');

        $this->assertSame(1, HandlesStatusWebhookController::$handled);
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
        Event::assertDispatchedTimes(WebhookHandled::class, 1);
    }

    public function testASubclassCanTurnTheTypedEventsOff()
    {
        Event::fake([...self::ALL, WebhookReceived::class]);
        $this->connectedTeam('secret-a');

        foreach (array_keys(self::catalogData()) as $name) {
            $this->deliverSigned($this->catalogEnvelope($name), 'secret-a', path: '/quiet-webhook')->assertOk();
        }

        foreach (self::ALL as $class) {
            Event::assertNotDispatched($class);
        }

        Event::assertDispatchedTimes(WebhookReceived::class, 13);
    }

    public function testThePerConnectionRouteFiresTheSameEvents()
    {
        Event::fake([...self::ALL, WebhookReceived::class]);
        $this->addWebhookKeyColumn();
        $this->enablePerConnection();
        $key = str_repeat('k', 40);
        $owner = $this->connectedTeam('whsec_k', '5581911110000', ['webhook_key' => $key])->zapmizerConnection;

        foreach (array_keys(self::catalogData()) as $name) {
            $this->deliverSigned($this->catalogEnvelope($name), 'whsec_k', path: '/zapmizer/webhook/' . $key)->assertOk();
        }

        $this->deliverSigned($this->catalogEnvelope('connection', ['state' => 'connecting'], ['wid' => null]), 'whsec_k', wid: null, path: '/zapmizer/webhook/' . $key)->assertOk();
        $this->deliverSigned($this->catalogEnvelope('message_status', null, ['wid' => '5581922220000@c.us']), 'whsec_k', path: '/zapmizer/webhook/' . $key)->assertOk();

        foreach (self::ALL as $class) {
            Event::assertDispatched($class, fn (WebhookEvent $event) => $event->connection->is($owner));
        }

        Event::assertDispatchedTimes(ConnectionStateChanged::class, 2);
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
        Event::assertDispatchedTimes(WebhookReceived::class, 14);
    }

    public function testTheEventsSurviveSerialization()
    {
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('message_status'), 'secret-a')->assertOk();

        Event::assertDispatched(MessageStatusUpdated::class, function (MessageStatusUpdated $event) {
            $copy = unserialize(serialize($event));

            $this->assertEquals($event->status, $copy->status);
            $this->assertEquals($event->envelope, $copy->envelope);
            $this->assertTrue($copy->connection->is($event->connection));
            $this->assertInstanceOf(Carbon::class, $copy->envelope->createdAt);

            return true;
        });
    }
}
