<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Fixtures\CountsWebhookArguments;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\Team;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConnectionWebhookTest extends TestCase
{
    use CreatesConnectionTables;
    use SignsWebhookDeliveries;
    use TogglesConnectionWebhook;

    private const KA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const KB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const OWNER_WID = '5581911110000@c.us';

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.webhook.secret', 'app-secret');
        $app['config']->set('zapmizer.webhook.per_connection', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->addWebhookKeyColumn();

        CountsWebhookArguments::$arguments = null;
        CountsWebhookArguments::$parameters = [];
    }

    private function keyedTeam(string $key, string $secret = 'whsec_a', string $number = '5581911110000', array $overrides = []): Team
    {
        return $this->connectedTeam($secret, $number, ['webhook_key' => $key] + $overrides);
    }

    /**
     * @return array<int, array{team: Team, key: string, secret: string, number: string}>
     */
    private function manyKeyedTeams(int $count): array
    {
        $teams = [];

        for ($i = 1; $i <= $count; $i++) {
            $number = '55819111' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
            $key = Str::random(40);
            $secret = "whsec_{$i}";

            $teams[$i] = ['team' => $this->keyedTeam($key, $secret, $number), 'key' => $key, 'secret' => $secret, 'number' => $number];
        }

        return $teams;
    }

    private function deliverTo(string $key, array $envelope, string $secret, ?string $wid = self::OWNER_WID, ?int $timestamp = null, ?string $signature = null): TestResponse
    {
        return $this->deliverSigned($envelope, $secret, $timestamp, $signature, $wid, '/zapmizer/webhook/' . $key);
    }

    private function postRaw(string $path, array $server, string $body = '{}'): TestResponse
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'] + $server, $body);
    }

    private function ownerEnvelope(): array
    {
        return $this->messageEnvelope(wid: self::OWNER_WID);
    }

    private function bodyOf(array $envelope): string
    {
        return json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function testC12TheOwnerSecretDispatchesTheUsualEvents()
    {
        Event::fake([MessageReceived::class, WebhookReceived::class, WebhookHandled::class]);
        $this->app->bind(WebhookController::class, CountsWebhookArguments::class);
        $owner = $this->keyedTeam(self::KA)->zapmizerConnection;

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_a')
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->connection->is($owner));
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->connection->is($owner));
        Event::assertDispatched(WebhookHandled::class, fn (WebhookHandled $event) => $event->connection->is($owner));
        $this->assertSame(1, CountsWebhookArguments::$arguments);
        $this->assertSame([], CountsWebhookArguments::$parameters);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: Closure}>
     */
    public static function rotationSignatures(): array
    {
        return [
            'previous' => [
                ['webhook_previous_secret' => 'whsec_old'],
                fn (string $payload) => 'v1=' . hash_hmac('sha256', $payload, 'whsec_old'),
            ],
            'both offered' => [
                [],
                fn (string $payload) => 'v1=' . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', $payload, 'whsec_a'),
            ],
        ];
    }

    /** @dataProvider rotationSignatures */
    #[DataProvider('rotationSignatures')]
    public function testC13ThePreviousSecretAndBothSignaturesAreAccepted(array $overrides, Closure $sign)
    {
        Event::fake([MessageReceived::class]);
        $this->keyedTeam(self::KA, overrides: $overrides);
        $envelope = $this->ownerEnvelope();
        $timestamp = now()->getTimestamp();

        $this->deliverTo(self::KA, $envelope, 'unused', timestamp: $timestamp, signature: $sign($timestamp . '.' . $this->bodyOf($envelope)))
            ->assertOk();

        Event::assertDispatchedTimes(MessageReceived::class, 1);
    }

    public function testC14AnotherConnectionsSignatureIsRefusedWithoutReadingIt()
    {
        $this->keyedTeam(self::KA);
        $this->keyedTeam(self::KB, 'whsec_b', '5581922220000');
        Log::spy();
        $this->watchQueries();

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_b')->assertUnauthorized();

        $queries = $this->connectionQueries();
        $this->assertCount(1, $queries);
        $this->assertSame([self::KA], $queries[0]['bindings']);
        $this->assertStringContainsString('"zapmizer_connections"."webhook_key"', $queries[0]['query']);
        Log::shouldHaveReceived('warning')
            ->with('zapmizer: delivery refused by signature.', Mockery::on(fn (array $context) => $context['reason'] === 'no known secret signs this delivery'))
            ->once();
    }

    public function testC15TheApplicationSecretDoesNotSignThisRoute()
    {
        $this->keyedTeam(self::KA);

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'app-secret')->assertUnauthorized();
    }

    public function testC16AnUnknownKeyIsTheSame401()
    {
        $this->keyedTeam(self::KA);
        Log::spy();

        $c14 = $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_b')->assertUnauthorized();

        $this->watchQueries();
        $c16 = $this->deliverTo(self::KB, $this->ownerEnvelope(), 'whsec_a')->assertUnauthorized();

        $this->assertCount(1, $this->connectionQueries());
        $this->assertSame($c14->getContent(), $c16->getContent());
        Log::shouldHaveReceived('warning')
            ->with('zapmizer: delivery refused by signature.', Mockery::on(fn (array $context) => $context['reason'] === 'unknown connection key'
                && !str_contains(json_encode($context), self::KB)))
            ->once();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedKeys(): array
    {
        return [
            '39 chars' => ['/zapmizer/webhook/' . str_repeat('a', 39)],
            '41 chars' => ['/zapmizer/webhook/' . str_repeat('a', 41)],
            'dash' => ['/zapmizer/webhook/' . str_repeat('a', 39) . '-'],
            'newline' => ['/zapmizer/webhook/' . self::KA . '%0A'],
            'unicode' => ['/zapmizer/webhook/' . str_repeat('a', 39) . '%C3%A9'],
        ];
    }

    /** @dataProvider malformedKeys */
    #[DataProvider('malformedKeys')]
    public function testC17AMalformedKeyIsRefusedWithoutAQuery(string $path)
    {
        $this->keyedTeam(self::KA);
        Log::spy();
        $this->watchQueries();

        $this->deliverSigned($this->ownerEnvelope(), 'whsec_a', path: $path)->assertUnauthorized();

        $this->assertSame([], $this->connectionQueries());
        Log::shouldHaveReceived('warning')
            ->with('zapmizer: delivery refused by signature.', Mockery::on(fn (array $context) => $context['reason'] === 'malformed connection key'))
            ->once();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function extraSegments(): array
    {
        return [
            'two segments' => ['/zapmizer/webhook/a/b'],
            'encoded slash' => ['/zapmizer/webhook/a%2Fb'],
        ];
    }

    /** @dataProvider extraSegments */
    #[DataProvider('extraSegments')]
    public function testC18AnExtraSegmentIsARouterMiss(string $path)
    {
        $this->keyedTeam(self::KA);
        $this->watchQueries();

        $this->deliverSigned($this->ownerEnvelope(), 'whsec_a', path: $path)->assertNotFound();

        $this->assertSame([], $this->connectionQueries());
    }

    public function testC19AnInactiveConnectionIs403()
    {
        $connection = $this->keyedTeam(self::KA, overrides: ['is_active' => false])->zapmizerConnection;
        Log::spy();

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_a')->assertForbidden();

        Log::shouldHaveReceived('warning')
            ->with('zapmizer: delivery refused, connection inactive.', ['connection_id' => $connection->getKey(), 'wid' => self::OWNER_WID])
            ->once();
    }

    public function testC20ADeletedConnectionIs401WithoutAScan()
    {
        $team = $this->keyedTeam(self::KA);
        $this->keyedTeam(self::KB, 'whsec_b', '5581922220000');
        $this->keyedTeam(str_repeat('c', 40), 'whsec_c', '5581933330000');
        $team->zapmizerConnection->delete();
        $this->watchQueries();

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_a')->assertUnauthorized();

        $this->assertCount(1, $this->connectionQueries());
    }

    public function testC21AnUnreadableSecretIs401AndReadsNoOtherRow()
    {
        $this->keyedTeam(self::KA);
        $this->keyedTeam(self::KB, 'whsec_b', '5581922220000');
        DB::table('zapmizer_connections')->where('webhook_key', self::KA)->update([
            'webhook_secret' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('whsec_a'),
        ]);
        Log::spy();
        $this->watchQueries();

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_a')->assertUnauthorized();

        $this->assertCount(1, $this->connectionQueries());
        Log::shouldHaveReceived('error')
            ->with('zapmizer: unreadable webhook secret, connection skipped while matching.', Mockery::any())
            ->once();
    }

    public function testC22ARowWithoutASecretIs401()
    {
        $this->keyedTeam(self::KA, overrides: ['webhook_secret' => null]);

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_a')->assertUnauthorized();
    }

    /**
     * Each builds the server variables from the body it signs.
     *
     * @return array<string, array{0: Closure}>
     */
    public static function badHeaders(): array
    {
        return [
            'none' => [fn (string $body) => []],
            'timestamp only' => [fn (string $body) => ['HTTP_X_ZAPMIZER_TIMESTAMP' => (string) now()->getTimestamp()]],
            'signature only' => [fn (string $body) => ['HTTP_X_ZAPMIZER_SIGNATURE' => 'v1=' . hash_hmac('sha256', now()->getTimestamp() . '.' . $body, 'whsec_a')]],
            'old timestamp' => [function (string $body) {
                $timestamp = now()->getTimestamp() - 301;

                return [
                    'HTTP_X_ZAPMIZER_TIMESTAMP' => (string) $timestamp,
                    'HTTP_X_ZAPMIZER_SIGNATURE' => 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_a'),
                ];
            }],
            'no v1' => [fn (string $body) => [
                'HTTP_X_ZAPMIZER_TIMESTAMP' => (string) now()->getTimestamp(),
                'HTTP_X_ZAPMIZER_SIGNATURE' => 'v0=abc',
            ]],
        ];
    }

    /** @dataProvider badHeaders */
    #[DataProvider('badHeaders')]
    public function testC23BadHeadersAreRefusedBeforeAnyQuery(Closure $server)
    {
        $this->keyedTeam(self::KA);
        $body = json_encode($this->ownerEnvelope());
        $this->watchQueries();

        $this->postRaw('/zapmizer/webhook/' . self::KA, $server($body), $body)->assertUnauthorized();

        $this->assertSame([], $this->connectionQueries());
    }

    public function testC24AnUnsignedVerifyNumberIsRefusedHere()
    {
        Event::fake([WebhookReceived::class]);
        $this->keyedTeam(self::KA);

        $this->postRaw('/zapmizer/webhook/' . self::KA, [], json_encode(['name' => 'verify_number.verified', 'data' => ['number' => '5511999999999']]))
            ->assertUnauthorized();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testC26AConnectionEventWithoutWidIsFoundByTheKey()
    {
        Event::fake([WebhookReceived::class]);
        $owner = $this->keyedTeam(self::KA)->zapmizerConnection;
        $this->manyKeyedTeams(49);
        $this->watchQueries();

        $this->deliverTo(self::KA, ['name' => 'connection', 'wid' => null, 'data' => ['status' => 'qr_pending']], 'whsec_a', wid: null)
            ->assertOk()
            ->assertSeeText('Webhook Received');

        $this->assertCount(1, $this->connectionQueries());
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->connection->is($owner));
    }

    private function fakeDeliveryEvents(): void
    {
        Event::fake([MessageReceived::class, WebhookReceived::class, WebhookHandled::class]);
    }

    private function assertNothingDispatched(): void
    {
        Event::assertNotDispatched(MessageReceived::class);
        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(WebhookHandled::class);
    }

    public function testC27AnotherNumbersEventIsAcknowledgedAndDropped()
    {
        $this->fakeDeliveryEvents();
        $connection = $this->keyedTeam(self::KA)->zapmizerConnection;
        Log::spy();

        $this->deliverTo(self::KA, $this->messageEnvelope(wid: '5581922220000@c.us'), 'whsec_a', wid: '5581922220000@c.us')
            ->assertOk()
            ->assertSeeText('Webhook Received');

        $this->assertNothingDispatched();
        Log::shouldHaveReceived('info')
            ->with('zapmizer: delivery for another number discarded.', ['connection_id' => $connection->getKey(), 'wid' => '5581922220000@c.us'])
            ->once();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ninthDigitVariants(): array
    {
        return [
            'wid with the ninth digit' => ['5581999990000@c.us', '558199990000'],
            'number with the ninth digit' => ['558199990000@c.us', '5581999990000'],
        ];
    }

    /** @dataProvider ninthDigitVariants */
    #[DataProvider('ninthDigitVariants')]
    public function testC28TheNinthDigitVariantIsTheSameNumber(string $wid, string $number)
    {
        $this->fakeDeliveryEvents();
        $this->keyedTeam(self::KA, number: $number);

        $this->deliverTo(self::KA, $this->messageEnvelope(wid: $wid), 'whsec_a', wid: $wid)
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        Event::assertDispatchedTimes(MessageReceived::class, 1);
    }

    public function testC28bASubscriberStartingWith1HasNoVariant()
    {
        $this->fakeDeliveryEvents();
        $this->keyedTeam(self::KA, number: '558112345678');

        $this->deliverTo(self::KA, $this->messageEnvelope(wid: '5581912345678@c.us'), 'whsec_a', wid: '5581912345678@c.us')
            ->assertOk()
            ->assertSeeText('Webhook Received');

        $this->assertNothingDispatched();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function widsThatAreNotANumber(): array
    {
        return [
            'int' => [5581911110000],
            'array' => [['5581911110000@c.us']],
            'empty' => [''],
            'suffix only' => ['@c.us'],
        ];
    }

    /** @dataProvider widsThatAreNotANumber */
    #[DataProvider('widsThatAreNotANumber')]
    public function testC29AWidThatIsNotANumberIsDropped(mixed $wid)
    {
        $this->fakeDeliveryEvents();
        $this->keyedTeam(self::KA);

        $this->deliverTo(self::KA, ['wid' => $wid] + $this->messageEnvelope(), 'whsec_a')
            ->assertOk()
            ->assertSeeText('Webhook Received');

        $this->assertNothingDispatched();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function otherWidSuffixes(): array
    {
        return [
            'lid' => ['5581911110000@lid'],
            's.whatsapp.net' => ['5581911110000@s.whatsapp.net'],
        ];
    }

    /** @dataProvider otherWidSuffixes */
    #[DataProvider('otherWidSuffixes')]
    public function testAnotherWidSuffixMatchesByTheDigits(string $wid)
    {
        $this->fakeDeliveryEvents();
        $this->keyedTeam(self::KA);

        $this->deliverTo(self::KA, $this->messageEnvelope(wid: $wid), 'whsec_a')
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        Event::assertDispatchedTimes(MessageReceived::class, 1);
    }

    public function testAConnectionWithoutANumberOnlyTakesEventsWithoutWid()
    {
        $this->fakeDeliveryEvents();
        $this->keyedTeam(self::KA, overrides: ['phone_number' => null]);

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_a')
            ->assertOk()
            ->assertSeeText('Webhook Received');
        $this->assertNothingDispatched();

        $this->deliverTo(self::KA, $this->messageEnvelope(), 'whsec_a')
            ->assertOk()
            ->assertSeeText('Webhook Handled');
        Event::assertDispatchedTimes(MessageReceived::class, 1);
    }

    public function testC43AKeyNotYetStoredIs401()
    {
        $this->connectedTeam('whsec_a');
        $this->watchQueries();

        $this->deliverTo(self::KA, $this->ownerEnvelope(), 'whsec_a')->assertUnauthorized();

        $this->assertCount(1, $this->connectionQueries());
    }

    public function testC11AConnectionWithoutAKeyStaysOnTheSharedRoute()
    {
        Event::fake([MessageReceived::class]);
        $connection = $this->connectedTeam('whsec_a')->zapmizerConnection;

        $this->deliverSigned($this->messageEnvelope(), 'whsec_a')->assertOk();

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->connection->is($connection));
    }

    public function testC44AReplayOnTheSharedRouteIsAcceptedAsTheOwner()
    {
        Event::fake([MessageReceived::class]);
        $owner = $this->keyedTeam(self::KA)->zapmizerConnection;

        $this->deliverSigned($this->ownerEnvelope(), 'whsec_a')->assertOk();

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->connection->is($owner));
    }

    public function testOneSelectPerDeliveryWhateverTheTableSize()
    {
        $teams = $this->manyKeyedTeams(50);
        $target = $teams[37];
        $wid = $target['number'] . '@c.us';

        $this->watchQueries();
        $this->deliverTo($target['key'], $this->messageEnvelope(wid: $wid), $target['secret'], wid: $wid)->assertOk();
        $hit = $this->connectionQueries();

        $this->watchQueries();
        $this->deliverTo(self::KB, $this->messageEnvelope(wid: $wid), $target['secret'], wid: $wid)->assertUnauthorized();
        $miss = $this->connectionQueries();

        $this->assertCount(1, $hit);
        $this->assertCount(1, $miss);
        $this->assertStringStartsWith('select', $hit[0]['query']);
        $this->assertStringStartsWith('select', $miss[0]['query']);
    }

    public function testATrailingSlashReachesTheSameRoute()
    {
        $this->keyedTeam(self::KA);

        $this->deliverSigned($this->ownerEnvelope(), 'whsec_a', path: '/zapmizer/webhook/' . self::KA . '/')->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notAJsonObject(): array
    {
        return [
            'json string' => ['"x"'],
            'text' => ['hello'],
        ];
    }

    /** @dataProvider notAJsonObject */
    #[DataProvider('notAJsonObject')]
    public function testABodyThatIsNotAJsonObjectReachesTheController(string $body)
    {
        $this->keyedTeam(self::KA);
        $timestamp = now()->getTimestamp();

        $this->postRaw('/zapmizer/webhook/' . self::KA, [
            'HTTP_X_ZAPMIZER_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_ZAPMIZER_SIGNATURE' => 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_a'),
        ], $body)->assertStatus(400);
    }
}
