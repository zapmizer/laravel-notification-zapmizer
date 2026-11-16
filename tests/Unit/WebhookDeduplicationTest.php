<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Events\ConnectionStateChanged;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\MessageStatusUpdated;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyWebhookSignature;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Fixtures\BuildsCatalogDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\FailsOnceWebhookController;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\UnavailableCacheStore;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Webhook\Deduplicator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class WebhookDeduplicationTest extends TestCase
{
    use BuildsCatalogDeliveries, CreatesConnectionTables, SignsWebhookDeliveries, TogglesConnectionWebhook;

    private const EVENTS = [
        WebhookReceived::class, WebhookHandled::class, MessageReceived::class,
        MessageStatusUpdated::class, ConnectionStateChanged::class,
    ];

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.webhook.secret', 'app-secret');
    }

    protected function defineRoutes($router)
    {
        $router->post('fails-once', [FailsOnceWebhookController::class, 'handleWebhook'])
            ->middleware(VerifyWebhookSignature::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        FailsOnceWebhookController::$calls = 0;
    }

    private function enable(int $ttl = 21600, ?string $store = null): void
    {
        config()->set('zapmizer.webhook.deduplicate', true);
        config()->set('zapmizer.webhook.deduplicate_ttl', $ttl);
        config()->set('zapmizer.webhook.deduplicate_store', $store);
    }

    public function testItIsOffByDefault()
    {
        Event::fake(self::EVENTS);
        $this->connectedTeam('secret-a');
        $this->assertFalse(config('zapmizer.webhook.deduplicate'));
        $this->assertSame(21600, config('zapmizer.webhook.deduplicate_ttl'));
        $this->assertNull(config('zapmizer.webhook.deduplicate_store'));
        $status = $this->catalogEnvelope('message_status');
        $message = $this->catalogEnvelope('message', $this->messageEnvelope()['data']);

        foreach ([$status, $status, $message, $message] as $envelope) {
            $this->deliverSigned($envelope, 'secret-a')->assertOk();
        }

        Event::assertDispatchedTimes(WebhookReceived::class, 4);
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 2);
        Event::assertDispatchedTimes(MessageReceived::class, 2);
        Event::assertDispatchedTimes(WebhookHandled::class, 2);
    }

    public function testARepeatedIdIsAcknowledgedAndFiresNothing()
    {
        $this->enable();
        Event::fake(self::EVENTS);
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');
        $message = $this->catalogEnvelope('message', $this->messageEnvelope()['data']);

        $this->deliverSigned($status, 'secret-a')->assertOk()->assertSeeText('Webhook Received');
        $this->deliverSigned($message, 'secret-a')->assertOk()->assertSeeText('Webhook Handled');
        $this->deliverSigned($status, 'secret-a')->assertOk()->assertSeeText('Webhook Received');
        $this->deliverSigned($message, 'secret-a')->assertOk()->assertSeeText('Webhook Received');

        Event::assertDispatchedTimes(WebhookReceived::class, 2);
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
        Event::assertDispatchedTimes(MessageReceived::class, 1);
        Event::assertDispatchedTimes(WebhookHandled::class, 1);
    }

    public function testTwoOccurrencesWithTheirOwnIdsAreBothProcessed()
    {
        $this->enable();
        Event::fake(self::EVENTS);
        $this->connectedTeam('secret-a');
        $drop = ['state' => 'disconnected', 'reason' => 'NAVIGATION', 'kind' => 'connection_lost'];

        $this->deliverSigned($this->catalogEnvelope('connection', $drop), 'secret-a')->assertOk();
        $this->deliverSigned($this->catalogEnvelope('connection', $drop), 'secret-a')->assertOk();

        Event::assertDispatchedTimes(ConnectionStateChanged::class, 2);
    }

    public function testDeliveriesWithoutAnIdAreAlwaysProcessed()
    {
        $this->enable();
        Event::fake(self::EVENTS);
        $this->connectedTeam('secret-a');
        $legacy = $this->messageEnvelope();
        $noId = $this->catalogEnvelope('message_status');
        unset($noId['id']);

        foreach ([$legacy, $legacy, $noId, $noId, ['id' => ''] + $noId, ['id' => ''] + $noId] as $envelope) {
            $this->deliverSigned($envelope, 'secret-a')->assertOk();
        }

        Event::assertDispatchedTimes(MessageReceived::class, 2);
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 4);
    }

    public function testUnsignedVerifyNumberEventsAreNeverDeduplicated()
    {
        // Anyone can POST one: an id there must not be able to burn the id
        // of a real, signed event.
        $this->enable();
        Event::fake(self::EVENTS);
        $user = User::create(['name' => 'Test', 'whatsapp_number' => '5511999999999']);
        $user->whatsappVerification()->create(['number' => '5511999999999', 'status' => WhatsappVerified::STATUS_AWAITING]);
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');
        $forged = ['id' => $status['id'], 'name' => 'verify_number.verified', 'data' => ['number' => '5511999999999']];

        $this->deliverUnsigned($forged)->assertOk();
        $this->deliverUnsigned($forged)->assertOk();
        $this->deliverSigned($status, 'secret-a')->assertOk();

        Event::assertDispatchedTimes(WebhookHandled::class, 2);
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
    }

    public function testAFailedFirstAttemptLetsTheRetryThrough()
    {
        $this->enable();
        Event::fake([WebhookReceived::class]);
        $this->connectedTeam('secret-a');
        $calls = 0;
        Event::listen(MessageStatusUpdated::class, function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('database is down');
            }
        });
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'secret-a')->assertStatus(500);
        $this->deliverSigned($status, 'secret-a')->assertOk();
        $this->deliverSigned($status, 'secret-a')->assertOk();

        $this->assertSame(2, $calls);
        Event::assertDispatchedTimes(WebhookReceived::class, 2);
    }

    public function testAServerErrorAnsweredByAHandlerLetsTheRetryThrough()
    {
        $this->enable();
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'secret-a', path: '/fails-once')->assertStatus(503);
        $this->deliverSigned($status, 'secret-a', path: '/fails-once')->assertOk()->assertSeeText('Webhook Handled');
        $this->deliverSigned($status, 'secret-a', path: '/fails-once')->assertOk()->assertSeeText('Webhook Received');

        $this->assertSame(2, FailsOnceWebhookController::$calls);
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 2);
    }

    public function testTheIdIsForgottenAfterTheTtl()
    {
        $this->enable(ttl: 60);
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'secret-a')->assertOk();
        $this->travel(59)->seconds();
        $this->deliverSigned($status, 'secret-a')->assertOk();
        $this->travel(2)->seconds();
        $this->deliverSigned($status, 'secret-a')->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 2);
    }

    public function testTheConfiguredStoreIsUsed()
    {
        config()->set('cache.stores.webhooks', ['driver' => 'array']);
        $this->enable(store: 'webhooks');
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'secret-a')->assertOk();

        $this->assertNotNull(Cache::store('webhooks')->get('zapmizer:webhook:' . sha1($status['id'])));
        $this->assertNull(Cache::store()->get('zapmizer:webhook:' . sha1($status['id'])));
    }

    public function testAnUnavailableCacheProcessesTheDeliveryAndLogs()
    {
        Cache::extend('unavailable', fn () => new Repository(new UnavailableCacheStore()));
        config()->set('cache.stores.down', ['driver' => 'unavailable']);
        $this->enable(store: 'down');
        Event::fake([MessageStatusUpdated::class]);
        Log::spy();
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'secret-a')->assertOk();
        $this->deliverSigned($status, 'secret-a')->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 2);
        Log::shouldHaveReceived('warning')
            ->with('zapmizer: webhook deduplication unavailable, delivery processed.', \Mockery::on(fn (array $context) => $context['id'] === $status['id']
                && str_contains($context['exception'], 'Connection refused')))
            ->twice();
    }

    public function testAStoreThatIsNotConfiguredProcessesTheDeliveryAndLogs()
    {
        $this->enable(store: 'missing');
        Event::fake([MessageStatusUpdated::class]);
        Log::spy();
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('message_status'), 'secret-a')->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'deduplication unavailable'))->once();
    }

    public function testAFailedFirstAttemptWithTheCacheDownStillAnswers500()
    {
        Cache::extend('unavailable', fn () => new Repository(new UnavailableCacheStore()));
        config()->set('cache.stores.down', ['driver' => 'unavailable']);
        $this->enable(store: 'down');
        Event::fake([WebhookReceived::class]);
        Event::listen(MessageStatusUpdated::class, fn () => throw new RuntimeException('database is down'));
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->catalogEnvelope('message_status'), 'secret-a')->assertStatus(500);
    }

    public function testThePerConnectionRouteDeduplicatesToo()
    {
        $this->enable();
        Event::fake(self::EVENTS);
        $this->addWebhookKeyColumn();
        $this->enablePerConnection();
        $key = str_repeat('k', 40);
        $this->connectedTeam('whsec_k', '5581911110000', ['webhook_key' => $key]);
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'whsec_k', path: '/zapmizer/webhook/' . $key)->assertOk();
        $this->deliverSigned($status, 'whsec_k', path: '/zapmizer/webhook/' . $key)->assertOk();

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
    }

    public function testAProcessedIdIsKeptForTheWholeTtl()
    {
        $this->enable(ttl: 3600);
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'secret-a')->assertOk();
        $this->travel(3500)->seconds();
        $this->deliverSigned($status, 'secret-a')->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
    }

    public function testAClaimThatNeverFinishesExpiresAfterTheProcessingWindow()
    {
        // A worker killed mid-delivery (no exception, no response) must not
        // burn the id for the whole TTL.
        $this->enable();
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');
        $this->assertTrue(app(Deduplicator::class)->claim($status));

        $this->deliverSigned($status, 'secret-a')->assertOk()->assertSeeText('Webhook Received');
        Event::assertNotDispatched(MessageStatusUpdated::class);

        $this->travel(Deduplicator::PROCESSING_TTL + 1)->seconds();
        $this->deliverSigned($status, 'secret-a')->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function ttlsThatFallBackToTheDefault(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'empty' => [''], 'null' => [null]];
    }

    /** @dataProvider ttlsThatFallBackToTheDefault */
    #[DataProvider('ttlsThatFallBackToTheDefault')]
    public function testAnEmptyOrNonPositiveTtlUsesTheDefault(mixed $ttl)
    {
        $this->enable();
        config()->set('zapmizer.webhook.deduplicate_ttl', $ttl);
        Event::fake([MessageStatusUpdated::class]);
        $this->connectedTeam('secret-a');
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'secret-a')->assertOk();
        $this->travel(21500)->seconds();
        $this->deliverSigned($status, 'secret-a')->assertOk();
        $this->travel(200)->seconds();
        $this->deliverSigned($status, 'secret-a')->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 2);
    }

    public function testADeliveryDroppedByTheWidFilterDoesNotBurnTheId()
    {
        // The same team event reaches the URL of each of its connections;
        // the one for another number is dropped before the controller.
        $this->enable();
        Event::fake([MessageStatusUpdated::class]);
        $this->addWebhookKeyColumn();
        $this->enablePerConnection();
        $other = str_repeat('a', 40);
        $owner = str_repeat('b', 40);
        $this->connectedTeam('whsec_a', '5581922220000', ['webhook_key' => $other]);
        $this->connectedTeam('whsec_b', '5581911110000', ['webhook_key' => $owner]);
        $status = $this->catalogEnvelope('message_status');

        $this->deliverSigned($status, 'whsec_a', path: '/zapmizer/webhook/' . $other)->assertOk();
        Event::assertNotDispatched(MessageStatusUpdated::class);

        $this->deliverSigned($status, 'whsec_b', path: '/zapmizer/webhook/' . $owner)->assertOk();
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
    }
}
