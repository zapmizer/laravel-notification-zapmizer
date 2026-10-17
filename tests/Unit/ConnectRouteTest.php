<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\ResolvesAuthenticatedUser;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\DrivesTheConnectRoutes;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\ExternalIdTeam;
use NotificationChannels\Zapmizer\Test\Fixtures\KeyedConnectController;
use NotificationChannels\Zapmizer\Test\Fixtures\PlainModel;
use NotificationChannels\Zapmizer\Test\Fixtures\PlainUser;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesFirstTeam;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesExternalIdTeam;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesNothing;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesPlainModel;
use NotificationChannels\Zapmizer\Test\Fixtures\Team;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Concerns\InvalidBaseUris;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConnectRouteTest extends TestCase
{
    use AssertsContract;
    use CreatesConnectionTables;
    use DrivesTheConnectRoutes;
    use FakesZapmizerHttp;
    use InvalidBaseUris;

    /** @var array<int, array{request: \GuzzleHttp\Psr7\Request}> */
    protected array $history = [];

    /** @var array<int, \Throwable> */
    protected array $reported = [];

    protected function tearDown(): void
    {
        ExternalIdTeam::$externalId = null;
        KeyedConnectController::$storedWith = null;

        parent::tearDown();
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.base_uri', 'http://zap.test/api/');
        $app['config']->set('zapmizer.partner.id', 'partner-id');
        $app['config']->set('zapmizer.partner.secret', 'partner-secret');
        // The resolver connects the user's team, as a multi-tenant app would.
        $app['config']->set('zapmizer.connect.resolver', ResolvesFirstTeam::class);
    }

    /**
     * A team that authorized (has a token) but has not paired a number yet.
     */
    protected function authorizedTeam(array $overrides = []): Team
    {
        $team = $this->team();
        $team->zapmizerConnection()->create(array_merge([
            'api_token' => 'team-token',
            'zapmizer_team_id' => 7,
            'zapmizer_team_name' => 'Acme',
            'is_active' => false,
        ], $overrides));

        return $team->fresh();
    }

    protected function instanceState(string $state, bool $online = false, ?string $number = '5581911110000'): string
    {
        return json_encode(['data' => [
            'id' => 9, 'state' => $state, 'state_label' => $state, 'is_online' => $online, 'is_up' => $online,
            'qrcode' => null, 'qrcode_available_at' => null, 'qrcode_expires_at' => null, 'number' => $number,
        ]]);
    }

    protected function pendingSession(): array
    {
        return [ConnectController::SESSION_KEY => [
            'state' => 's', 'connectable' => Team::class . ':' . $this->team()->id, 'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]];
    }

    // --- routes ---------------------------------------------------------

    public function testRoutesAreRegisteredBehindAuth()
    {
        foreach (['show', 'destroy', 'start', 'callback'] as $name) {
            $route = Route::getRoutes()->getByName("zapmizer.connect.{$name}");

            $this->assertNotNull($route, "zapmizer.connect.{$name}");
            $this->assertContains('auth', $route->gatherMiddleware(), "zapmizer.connect.{$name}");
        }

        $this->assertEquals('zapmizer/connect/callback', Route::getRoutes()->getByName('zapmizer.connect.callback')->uri());
    }

    public function testTheWizardRoutesAreGone()
    {
        // Pairing happens on Zapmizer's hosted page now: nothing here creates,
        // lists or polls instances.
        $this->actingAsUser();
        $this->authorizedTeam();

        foreach (['instance', 'instances', 'connection'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName("zapmizer.connect.{$name}"), "zapmizer.connect.{$name}");
        }

        $this->postJson('/zapmizer/connect/instance')->assertNotFound();
        $this->getJson('/zapmizer/connect/instances')->assertNotFound();
        $this->getJson('/zapmizer/connect/connection')->assertNotFound();
    }

    public function testShowReturnsTheConnectionState()
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->getJson(route('zapmizer.connect.show'))->assertOk()->assertExactJson(['connection' => null]);

        $this->authorizedTeam();

        $response = $this->getJson(route('zapmizer.connect.show'))->assertOk();
        $response->assertJsonPath('connection.is_active', false);
        $response->assertJsonPath('connection.api_token_masked', '••••oken');
        $this->assertArrayNotHasKey('api_token', $response->json('connection'));
        // Without `live` nothing reaches Zapmizer and nothing live is claimed.
        $this->assertArrayNotHasKey('state', $response->json('connection'));
        $this->assertCount(0, $this->history);
    }

    public function testShowLiveQueriesThePairedInstance()
    {
        $this->fakeHttp(new Response(200, [], $this->instanceState('connected', online: true)));
        $this->actingAsUser();
        $this->authorizedTeam(['phone_number' => '5581911110000', 'bot_instance_id' => 9, 'connected_at' => now(), 'is_active' => true]);

        $response = $this->getJson(route('zapmizer.connect.show', ['live' => 1]))->assertOk();
        $response->assertJsonPath('connection.state', 'connected');
        $response->assertJsonPath('connection.is_online', true);
        $response->assertJsonPath('connection.phone_number', '5581911110000');
        $response->assertJsonPath('connection.is_active', true);
        $this->assertArrayNotHasKey('api_token', $response->json('connection'));
        $this->assertArrayNotHasKey('webhook_secret', $response->json('connection'));

        $request = $this->history[0]['request'];
        $this->assertEquals('http://zap.test/api/bot-instances/9/connection', (string) $request->getUri());
        $this->assertEquals('Bearer team-token', $request->getHeaderLine('Authorization'));
    }

    public function testShowLiveWithNothingToQueryClaimsNothing()
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->getJson(route('zapmizer.connect.show', ['live' => 1]))->assertOk()->assertExactJson(['connection' => null]);

        $this->authorizedTeam(['bot_instance_id' => null]);

        $this->getJson(route('zapmizer.connect.show', ['live' => 1]))
            ->assertOk()
            ->assertJsonPath('connection.state', null)
            ->assertJsonPath('connection.is_online', false);
        $this->assertCount(0, $this->history);
    }

    /** @dataProvider liveFailures */
    #[DataProvider('liveFailures')]
    public function testShowLiveNamesTheFailureAsAState(Response $response, string $state)
    {
        $this->fakeHttp($response);
        $this->actingAsUser();
        $this->authorizedTeam(['phone_number' => '5581911110000', 'bot_instance_id' => 9, 'is_active' => true]);

        $this->getJson(route('zapmizer.connect.show', ['live' => 1]))
            ->assertOk()
            ->assertJsonPath('connection.state', $state)
            ->assertJsonPath('connection.is_online', false);
    }

    public static function liveFailures(): array
    {
        return [
            'token revoked' => [new Response(401, [], '{}'), 'reauth_required'],
            'instance gone' => [new Response(404, [], '{}'), 'instance_gone'],
            'zapmizer down' => [new Response(503, [], ''), 'zapmizer_unavailable'],
            'html instead of json' => [new Response(200, ['Content-Type' => 'text/html'], '<html></html>'), 'zapmizer_unavailable'],
        ];
    }

    /** @dataProvider undeclaredLiveRefusals */
    #[DataProvider('undeclaredLiveRefusals')]
    public function testM9AnUndeclaredRefusalOfTheInstanceIsUnavailableNotGone(Response $response)
    {
        $this->fakeHttp($response);
        $this->actingAsUser();
        $this->authorizedTeam(['phone_number' => '5581911110000', 'bot_instance_id' => 9, 'is_active' => true]);

        $this->getJson(route('zapmizer.connect.show', ['live' => 1]))
            ->assertOk()
            ->assertJsonPath('connection.state', 'zapmizer_unavailable')
            ->assertJsonPath('connection.is_online', false);
    }

    public static function undeclaredLiveRefusals(): array
    {
        return [
            '429' => [new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '5'], '{"message":"Too Many Attempts."}')],
            '403' => [new Response(403, ['Content-Type' => 'application/json'], '{"message":"This action is unauthorized."}')],
            '422' => [new Response(422, ['Content-Type' => 'application/json'], '{"message":"Invalid.","errors":{"id":["Invalid."]}}')],
        ];
    }

    // --- start ----------------------------------------------------------

    public function testS1TheStartSendsTheWholeSession()
    {
        $this->fakeHttp($this->sessionCreated());
        $this->actingAsUser();

        $this->startConnect()
            ->assertOk()
            ->assertExactJson(['url' => 'http://zap.test/connect/1?s=abc', 'expires_at' => '2026-09-07T01:00:00+00:00']);

        $pending = session(ConnectController::SESSION_KEY);
        $this->assertEquals(40, strlen($pending['state']));
        $this->assertEquals(Team::class . ':' . $this->team()->id, $pending['connectable']);
        $this->assertArrayNotHasKey('webhook_key', $pending);

        $body = $this->sentBody(0);
        $this->assertSame(['redirect_uri', 'state', 'webhook_url', 'expires_in', 'external_id'], array_keys($body));
        $this->assertSame('http://localhost/zapmizer/connect/callback', $body['redirect_uri']);
        $this->assertSame($pending['state'], $body['state']);
        // Zapmizer registers the receiver during the pairing: the route goes
        // along with the session.
        $this->assertSame('http://localhost/zapmizer/webhook', $body['webhook_url']);
        $this->assertSame(1800, $body['expires_in']);
        $this->assertSame((string) $this->team()->id, $body['external_id']);
        $this->assertMatchesSchema('#/paths/~1connect~1sessions/post/requestBody/content/application~1json/schema', $body);
        $this->assertEquals('partner-id|partner-secret', $this->history[0]['request']->getHeaderLine('X-Partner-Key'));
    }

    public function testS2AnOverriddenExternalIdGoesAsIs()
    {
        config()->set('zapmizer.connect.resolver', ResolvesExternalIdTeam::class);
        ExternalIdTeam::$externalId = 'tenant-9';
        $this->fakeHttp($this->sessionCreated());
        $this->actingAsUser();

        $this->startConnect()->assertOk();

        $this->assertSame('tenant-9', $this->sentBody(0)['external_id']);
    }

    /** @dataProvider invalidExternalIds */
    #[DataProvider('invalidExternalIds')]
    public function testS3AnInvalidExternalIdIsAProgrammingError(string $id)
    {
        config()->set('zapmizer.connect.resolver', ResolvesExternalIdTeam::class);
        ExternalIdTeam::$externalId = $id;
        $this->recordReports();
        $this->fakeHttp();
        $this->actingAsUser();

        $this->startConnect()->assertStatus(500);

        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(InvalidArgumentException::class, $this->reported[0]);
        $this->assertCount(0, $this->history);
    }

    public static function invalidExternalIds(): array
    {
        return ['empty' => [''], 'space' => ['a b'], 'dot dot' => ['..']];
    }

    public function testTheStepSevenSeamsCarryTheKey()
    {
        $this->app->bind(ConnectController::class, KeyedConnectController::class);
        $this->fakeHttp($this->sessionCreated());
        $this->actingAsUser();

        $this->startConnect()->assertOk();

        $this->assertSame('fixed-key', session(ConnectController::SESSION_KEY)['webhook_key']);
        $this->assertSame('https://app.test/hook/fixed-key', $this->sentBody(0)['webhook_url']);
    }

    /** @dataProvider ttlFallbacks */
    #[DataProvider('ttlFallbacks')]
    public function testC1TheTtlFallsBackTo30(bool $present, mixed $value, int $minutes)
    {
        Carbon::setTestNow('2026-10-16 12:00:00');

        if ($present) {
            config()->set('zapmizer.connect.state_ttl_minutes', $value);
        } else {
            config()->set('zapmizer.connect', ['resolver' => ResolvesFirstTeam::class]);
        }

        $this->fakeHttp($this->sessionCreated());
        $this->actingAsUser();

        $this->startConnect()->assertOk();

        $this->assertSame(max(900, $minutes * 60), $this->sentBody(0)['expires_in']);
        $this->assertSame(now()->addMinutes($minutes)->toIso8601String(), session(ConnectController::SESSION_KEY)['expires_at']);
    }

    public static function ttlFallbacks(): array
    {
        return [
            'absent' => [false, null, 30],
            'null' => [true, null, 30],
            'zero' => [true, 0, 30],
            'negative' => [true, -5, 30],
            'text' => [true, 'abc', 30],
            'string 45' => [true, '45', 45],
            '0.5' => [true, 0.5, 30],
            '1.9' => [true, '1.9', 1],
        ];
    }

    public function testC2AShortTtlExpiresTheStateAndSendsTheFloor()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        config()->set('zapmizer.connect.state_ttl_minutes', 5);
        $this->fakeHttp($this->sessionCreated());
        $this->actingAsUser();

        $this->startConnect()->assertOk();
        $this->assertSame(900, $this->sentBody(0)['expires_in']);

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => session(ConnectController::SESSION_KEY)['state']]))
            ->assertOk()
            ->assertSee('status: "invalid_state"', false);
        $this->assertCount(1, $this->history);
    }

    public function testC5APublishedConfigKeepsItsTen()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        config()->set('zapmizer.connect.state_ttl_minutes', 10);
        $this->fakeHttp($this->sessionCreated());
        $this->actingAsUser();

        $this->startConnect()->assertOk();

        $this->assertSame(900, $this->sentBody(0)['expires_in']);
        $this->assertSame(now()->addMinutes(10)->toIso8601String(), session(ConnectController::SESSION_KEY)['expires_at']);
    }

    public function testATtlAboveADayIsCappedByTheClient()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        config()->set('zapmizer.connect.state_ttl_minutes', 2000);
        $this->fakeHttp($this->sessionCreated());
        $this->actingAsUser();

        $this->startConnect()->assertOk();

        $this->assertSame(86400, $this->sentBody(0)['expires_in']);
        $this->assertSame(now()->addMinutes(2000)->toIso8601String(), session(ConnectController::SESSION_KEY)['expires_at']);
    }

    /** @dataProvider credentialFailures */
    #[DataProvider('credentialFailures')]
    public function testStartAnswersAStableCodeWhenThePartnerKeyIsRefused(int $status)
    {
        Log::spy();
        // What Zapmizer really answers when the partner key is invalid: JSON
        // with a message — and, with debug on over there, a stack trace.
        $this->fakeHttp(new Response($status, [], json_encode([
            'message' => 'Unauthenticated.',
            'exception' => 'Illuminate\\Auth\\AuthenticationException',
            'trace' => [['file' => '/var/www/zapbot/app/Http/Middleware/PartnerKey.php', 'line' => 42]],
        ])));
        $this->actingAsUser();

        $response = $this->postJson(route('zapmizer.connect.start'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'partner_unauthorized']);

        // Nothing from Zapmizer reaches the client.
        $this->assertStringNotContainsString('Unauthenticated', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
    }

    public static function credentialFailures(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    public function testStartDoesNotLeakOtherFailures()
    {
        Log::spy();
        $this->fakeHttp(new Response(422, [], json_encode(['message' => 'The redirect uri field is required.'])));
        $this->actingAsUser();

        $response = $this->postJson(route('zapmizer.connect.start'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertStringNotContainsString('redirect uri', $response->getContent());
    }

    /** @dataProvider invalidBaseUris */
    #[DataProvider('invalidBaseUris')]
    public function testX18dStartWithAnInvalidBaseAnswersUnavailableAndSendsNothing(string $base)
    {
        Log::spy();
        config()->set('zapmizer.base_uri', $base);
        $this->fakeHttp();
        $this->actingAsUser();
        $this->recordReports();

        $this->postJson(route('zapmizer.connect.start'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertCount(1, $this->reported);
        $this->assertIsTheInvalidBaseUri($this->reported[0]);
        $this->assertCount(0, $this->history);
    }

    /** @dataProvider unexpectedStartAnswers */
    #[DataProvider('unexpectedStartAnswers')]
    public function testX18dStartWithAnUnexpectedAnswerAnswersUnavailableAndReports(Response $response)
    {
        Log::spy();
        $this->fakeHttp($response);
        $this->actingAsUser();
        $this->recordReports();

        $this->postJson(route('zapmizer.connect.start'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(ZapmizerConnectException::class, $this->reported[0]);
        $this->assertNotInstanceOf(ZapmizerApiException::class, $this->reported[0]);
    }

    public static function unexpectedStartAnswers(): array
    {
        return [
            'redirect' => [new Response(302, ['Location' => 'http://zap.test/login'])],
            'html' => [new Response(200, ['Content-Type' => 'text/html'], '<html></html>')],
        ];
    }

    public function testX18gStartWithoutPartnerCredentialsAnswersUnavailableAndReports()
    {
        Log::spy();
        config()->set('zapmizer.partner.secret', null);
        $this->fakeHttp();
        $this->actingAsUser();
        $this->recordReports();

        $this->postJson(route('zapmizer.connect.start'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertCount(1, $this->reported);
        $this->assertSame(ZapmizerConnectException::partnerCredentialsNotProvided()->getMessage(), $this->reported[0]->getMessage());
        $this->assertCount(0, $this->history);
    }

    public function testX18iStartWithAUserThatIsNotConnectableAnswersUnavailableAndReports()
    {
        Log::spy();
        config()->set('zapmizer.connect.resolver', ResolvesAuthenticatedUser::class);
        $this->actingAs(PlainUser::create(['name' => 'Plain']));
        $this->fakeHttp();
        $this->recordReports();

        $this->postJson(route('zapmizer.connect.start'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertCount(1, $this->reported);
        $this->assertStringContainsString(PlainUser::class, $this->reported[0]->getMessage());
        $this->assertCount(0, $this->history);
    }

    public function testX18hTheCallbackWithAnInvalidBaseIsAnExchangeFailureWithoutTheUrl()
    {
        Log::spy();
        config()->set('zapmizer.base_uri', 'https://u:p@h/api');
        $this->fakeHttp();
        $this->actingAsUser();
        $this->recordReports();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertSee('status: "exchange_failed"', false);

        Log::shouldHaveReceived('warning')
            ->with('zapmizer: connect callback failed.', ['exception' => ZapmizerConnectException::invalidBaseUri()->getMessage()])
            ->once();
        $this->assertCount(0, $this->history);
    }

    /** @dataProvider partnerRefusalsFromTheContract */
    #[DataProvider('partnerRefusalsFromTheContract')]
    public function testM37StartAnswersUnavailableForAPartnerRefusalFromTheContract(int $status, string $body, array $headers)
    {
        $this->assertMatchesContract('POST', '/connect/sessions', $status, $body, $headers);
        $this->assertStartAnswersUnavailableAndReports(new Response($status, ['Content-Type' => 'application/json'] + $headers, $body));
    }

    public static function partnerRefusalsFromTheContract(): array
    {
        return [
            '422' => [422, '{"message":"The redirect uri field is required.","errors":{"redirect_uri":["The redirect uri field is required."]}}', []],
            '429' => [429, '{"message":"Too Many Attempts."}', ['Retry-After' => '30']],
        ];
    }

    /** @dataProvider partnerRefusalsOutsideTheContract */
    #[DataProvider('partnerRefusalsOutsideTheContract')]
    public function testM37StartAnswersUnavailableForAPartnerRefusalOutsideTheContract(int $status, string $body)
    {
        $this->assertStartAnswersUnavailableAndReports(new Response($status, ['Content-Type' => 'application/json'], $body));
    }

    public static function partnerRefusalsOutsideTheContract(): array
    {
        return [
            '404' => [404, '{"message":"Not Found"}'],
            '409' => [409, '{"error":"something_new","message":"Conflict."}'],
            '429 without Retry-After' => [429, '{"message":"Too Many Attempts."}'],
        ];
    }

    protected function assertStartAnswersUnavailableAndReports(Response $response): void
    {
        Log::spy();
        $this->fakeHttp($response);
        $this->actingAsUser();
        $this->recordReports();

        $answer = $this->postJson(route('zapmizer.connect.start'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertStringNotContainsString('redirect uri', $answer->getContent());
        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(ZapmizerConnectException::class, $this->reported[0]);
    }

    public function testM42StartReportsARefusedPartnerKey()
    {
        Log::spy();
        $this->fakeHttp(new Response(401, ['Content-Type' => 'application/json'], '{"message":"Unauthenticated."}'));
        $this->actingAsUser();
        $this->recordReports();

        $this->postJson(route('zapmizer.connect.start'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'partner_unauthorized']);

        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(PartnerCredentialsException::class, $this->reported[0]);
    }

    /** @dataProvider withAndWithoutJson */
    #[DataProvider('withAndWithoutJson')]
    public function testM44StartAnswersTheSameJsonWithOrWithoutAcceptJson(array $headers)
    {
        Log::spy();
        $this->fakeHttp(
            new Response(401, ['Content-Type' => 'application/json'], '{"message":"Unauthenticated."}'),
            new Response(503, [], ''),
        );
        $this->actingAsUser();

        $this->post(route('zapmizer.connect.start'), [], $headers)
            ->assertStatus(503)
            ->assertExactJson(['code' => 'partner_unauthorized']);
        $this->post(route('zapmizer.connect.start'), [], $headers)
            ->assertStatus(503)
            ->assertExactJson(['code' => 'zapmizer_unavailable']);
    }

    /** @dataProvider withAndWithoutJson */
    #[DataProvider('withAndWithoutJson')]
    public function testM44NothingToConnectAnswersTheSameJsonWithOrWithoutAcceptJsonAndIsNotReported(array $headers)
    {
        $this->fakeHttp();
        config()->set('zapmizer.connect.resolver', ResolvesNothing::class);
        $this->actingAsUser();
        $this->recordReports();

        $this->get(route('zapmizer.connect.show'), $headers)->assertForbidden()->assertExactJson(['code' => 'no_connectable']);
        $this->post(route('zapmizer.connect.start'), [], $headers)->assertForbidden()->assertExactJson(['code' => 'no_connectable']);
        $this->delete(route('zapmizer.connect.destroy'), [], $headers)->assertForbidden()->assertExactJson(['code' => 'no_connectable']);

        $this->assertSame([], $this->reported);
        $this->assertCount(0, $this->history);
    }

    public static function withAndWithoutJson(): array
    {
        return [
            'Accept: application/json' => [['Accept' => 'application/json']],
            'browser Accept' => [['Accept' => 'text/html']],
        ];
    }

    public function testGuestsAreRejected()
    {
        Route::get('login', fn () => 'login')->name('login');

        $this->post(route('zapmizer.connect.start'))->assertRedirect(route('login'));
    }

    // --- callback -------------------------------------------------------

    public function testX1TheExchangeStoresAnActiveConnection()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        Log::spy();
        $this->fakeHttp(new Response(200, [], $this->tokenPayload()));
        $this->actingAsUser();

        $response = $this->withSession([ConnectController::SESSION_KEY => [
            'state' => 'the-state',
            'connectable' => Team::class . ':' . $this->team()->id,
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]])->get(route('zapmizer.connect.callback', ['code' => '12.secret', 'state' => 'the-state']));

        $response->assertOk()
            ->assertSee("source: 'zapmizer-connect'", false)
            ->assertSee('status: "ok"', false)
            ->assertSee('window.opener.postMessage', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals('1|sanctum', $connection->api_token);
        $this->assertEquals(7, $connection->zapmizer_team_id);
        $this->assertEquals('Acme', $connection->zapmizer_team_name);
        // The hosted page paired the number: everything lands at once.
        $this->assertEquals('5581911110000', $connection->phone_number);
        $this->assertEquals(9, $connection->bot_instance_id);
        $this->assertTrue($connection->connected_at->equalTo(now()));
        $this->assertEquals(42, $connection->webhook_id);
        $this->assertEquals('whsec_new', $connection->webhook_secret);
        $this->assertNull($connection->webhook_previous_secret);
        $this->assertTrue($connection->is_active);
        $this->assertTrue($this->team()->fresh()->hasActiveZapmizer());

        // The state is single-use; one call — no instance, no webhook registration.
        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertCount(1, $this->history);
        $this->assertEquals(['code' => '12.secret'], json_decode((string) $this->history[0]['request']->getBody(), true));
        Log::shouldNotHaveReceived('warning');
        $this->assertNoWebhookCalls();
    }

    public function testW1TheSameWebhookKeepsTheStoredSecret()
    {
        Log::spy();
        $this->fakeHttp(
            $this->sessionCreated(),
            $this->tokenAnswer(['webhook_secret' => 'whsec_1']),
            $this->sessionCreated(),
            $this->tokenAnswer(['token' => '2|sanctum', 'phone_number' => '5581922220000', 'bot_instance_id' => 10, 'webhook_secret' => null]),
        );
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(1, ZapmizerConnection::count());
        $this->assertEquals('2|sanctum', $connection->api_token);
        $this->assertEquals('5581922220000', $connection->phone_number);
        $this->assertEquals(10, $connection->bot_instance_id);
        $this->assertEquals(42, $connection->webhook_id);
        $this->assertEquals('whsec_1', $connection->webhook_secret);
        $this->assertNull($connection->webhook_previous_secret);
        $this->assertTrue($connection->is_active);
        $this->assertCount(4, $this->history);
        Log::shouldNotHaveReceived('warning');
        $this->assertNoWebhookCalls();
    }

    /** @dataProvider connectsWithoutASecret */
    #[DataProvider('connectsWithoutASecret')]
    public function testW2WithoutASecretTheConnectionSendsButCannotReceive(array $connects, int $webhookId)
    {
        Log::spy();
        $answers = [];

        foreach ($connects as $overrides) {
            $answers[] = $this->sessionCreated();
            $answers[] = $this->tokenAnswer($overrides);
        }

        $this->fakeHttp(...$answers);
        $this->actingAsUser();

        foreach ($connects as $overrides) {
            $response = $this->connect();
        }

        $response->assertSee('status: "webhook_failed"', false)
            ->assertSee("Connected and sending, but incoming messages can't be verified. Disconnect and connect again to receive.");

        $connection = $this->team()->zapmizerConnection;
        $this->assertTrue($connection->is_active);
        $this->assertSame($webhookId, $connection->webhook_id);
        $this->assertNull($connection->webhook_secret);
        $this->assertFalse($connection->toArray()['has_webhook_secret']);
        $this->assertTrue($this->team()->fresh()->hasActiveZapmizer());
        Log::shouldHaveReceived('warning')->with('zapmizer: connected without a webhook secret.', ['connection_id' => $connection->id, 'webhook_id' => $webhookId])->once();
        $this->assertCount(2 * count($connects), $this->history);
        $this->assertNoWebhookCalls();
    }

    public static function connectsWithoutASecret(): array
    {
        return [
            'first connect' => [[['webhook_secret' => null]], 42],
            'new webhook without secret' => [[[], ['token' => '2|sanctum', 'webhook_id' => 43, 'webhook_secret' => null]], 43],
        ];
    }

    public function testW3AnotherTeamForgetsThePairingWithoutCallingWebhooks()
    {
        Log::spy();
        $this->fakeHttp(
            $this->sessionCreated(),
            $this->tokenAnswer(),
            $this->sessionCreated(),
            $this->tokenAnswer(['token' => '2|sanctum', 'team_id' => 8, 'team_name' => 'Other', 'phone_number' => '5581933330000', 'bot_instance_id' => 30, 'webhook_id' => 77, 'webhook_secret' => 'whsec_77']),
        );
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(1, ZapmizerConnection::count());
        $this->assertEquals('2|sanctum', $connection->api_token);
        $this->assertEquals(8, $connection->zapmizer_team_id);
        $this->assertEquals('Other', $connection->zapmizer_team_name);
        // Nothing of the old team survives: number, instance and webhook are
        // the new team's, and the old previous secret is not carried over.
        $this->assertEquals('5581933330000', $connection->phone_number);
        $this->assertEquals(30, $connection->bot_instance_id);
        $this->assertNotNull($connection->connected_at);
        $this->assertEquals(77, $connection->webhook_id);
        $this->assertEquals('whsec_77', $connection->webhook_secret);
        $this->assertNull($connection->webhook_previous_secret);
        $this->assertTrue($connection->is_active);
        $this->assertCount(4, $this->history);
        Log::shouldNotHaveReceived('warning');
        $this->assertNoWebhookCalls();
    }

    public function testW4ANewWebhookOnTheSameTeamStoresItsSecret()
    {
        $this->fakeHttp(
            $this->sessionCreated(),
            $this->tokenAnswer(),
            $this->sessionCreated(),
            $this->tokenAnswer(['token' => '2|sanctum', 'webhook_id' => 43, 'webhook_secret' => 'whsec_43']),
        );
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(43, $connection->webhook_id);
        $this->assertEquals('whsec_43', $connection->webhook_secret);
        $this->assertNull($connection->webhook_previous_secret);
        $this->assertCount(4, $this->history);
        $this->assertNoWebhookCalls();
    }

    public function testCallbackRefusesATeamAlreadyConnectedElsewhere()
    {
        $this->fakeHttp(new Response(200, [], $this->tokenPayload(['token' => '2|sanctum'])));
        $this->actingAsUser();
        // Another connectable already holds Zapmizer team 7.
        Team::create(['name' => 'Other'])->zapmizerConnection()->create(['api_token' => 'other-token', 'zapmizer_team_id' => 7, 'is_active' => true]);

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "team_already_connected"', false);

        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertEquals(1, ZapmizerConnection::count());
    }

    public function testCallbackRefusesATeamConnectedElsewhereBetweenTheCheckAndTheSave()
    {
        $this->fakeHttp(new Response(200, [], $this->tokenPayload(['token' => '2|sanctum'])));
        $this->actingAsUser();
        $raced = false;
        ZapmizerConnection::creating(function () use (&$raced) {
            if ($raced) {
                return;
            }

            $raced = true;
            Team::create(['name' => 'Other'])->zapmizerConnection()->create(['api_token' => 'other-token', 'zapmizer_team_id' => 7, 'is_active' => true]);
        });

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "team_already_connected"', false);

        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertEquals(1, ZapmizerConnection::count());
    }

    public function testCallbackRefusesSwitchingToATeamConnectedElsewhere()
    {
        $this->fakeHttp(new Response(200, [], $this->tokenPayload(['token' => '2|sanctum', 'team_id' => 8, 'team_name' => 'Other'])));
        $this->actingAsUser();
        $this->authorizedTeam(['phone_number' => '5581911110000', 'bot_instance_id' => 9, 'is_active' => true, 'webhook_id' => 42, 'webhook_secret' => 'whsec_old']);
        Team::create(['name' => 'Other'])->zapmizerConnection()->create(['api_token' => 'other-token', 'zapmizer_team_id' => 8, 'is_active' => true]);

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "team_already_connected"', false);

        // Untouched: the refusal happens before anything is forgotten.
        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals('team-token', $connection->api_token);
        $this->assertEquals(7, $connection->zapmizer_team_id);
        $this->assertEquals(42, $connection->webhook_id);
        $this->assertCount(1, $this->history);
    }

    /** @dataProvider exchangeFailures */
    #[DataProvider('exchangeFailures')]
    public function testCallbackNeverAnswersA500ToThePopup(Response|\Throwable $response)
    {
        Log::spy();
        $this->fakeHttp($response);
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false)
            ->assertSee('window.opener.postMessage', false);

        $this->assertNull($this->team()->zapmizerConnection);
    }

    public static function exchangeFailures(): array
    {
        return [
            'html instead of json' => [new Response(200, ['Content-Type' => 'text/html'], '<html>maintenance</html>')],
            'json without token' => [new Response(200, [], '{"team_id": 7}')],
            'P9 json without user_id' => [new Response(200, [], '{"token":"1|sanctum","team_id":7,"team_name":"Acme"}')],
            'P9 user_id 0' => [new Response(200, [], '{"token":"1|sanctum","user_id":0,"team_id":7,"team_name":"Acme"}')],
            'P9 team_id null' => [new Response(200, [], '{"token":"1|sanctum","user_id":3,"team_id":null,"team_name":"Acme"}')],
            'P9 team_id "12.7"' => [new Response(200, [], '{"token":"1|sanctum","user_id":3,"team_id":"12.7","team_name":"Acme"}')],
            'partner key refused' => [new Response(401, [], '{}')],
            'zapmizer down' => [new Response(502, [], '')],
            'network' => [new ConnectException('timed out', new \GuzzleHttp\Psr7\Request('POST', 'connect/token'))],
            'cut json' => [new Response(200, ['Content-Type' => 'application/json'], '{"token":"1|sanc')],
        ];
    }

    public function testCallbackTreatsAnUnparseableExpiryAsInvalidState()
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession([ConnectController::SESSION_KEY => [
            'state' => 's', 'connectable' => Team::class . ':' . $this->team()->id, 'expires_at' => 'not-a-date',
        ]])->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "invalid_state"', false);

        $this->assertCount(0, $this->history);
    }

    /** @dataProvider invalidStates */
    #[DataProvider('invalidStates')]
    public function testCallbackRefusesABadState(array $session, array $query, string $status)
    {
        $this->fakeHttp();
        $this->actingAsUser();
        $team = $this->team();

        $session = array_map(fn ($value) => is_callable($value) ? $value($team) : $value, $session);

        $this->withSession($session === [] ? [] : [ConnectController::SESSION_KEY => $session])
            ->get(route('zapmizer.connect.callback', $query))
            ->assertOk()
            ->assertSee("status: \"{$status}\"", false);

        $this->assertNull($team->zapmizerConnection);
        $this->assertCount(0, $this->history);
    }

    public static function invalidStates(): array
    {
        $valid = fn (Team $team) => Team::class . ':' . $team->id;
        $future = now()->addMinutes(5)->toIso8601String();

        return [
            'no pending state' => [[], ['code' => 'c', 'state' => 's'], 'invalid_state'],
            'state mismatch' => [['state' => 'other', 'connectable' => $valid, 'expires_at' => $future], ['code' => 'c', 'state' => 's'], 'invalid_state'],
            'another connectable' => [['state' => 's', 'connectable' => 'Other:1', 'expires_at' => $future], ['code' => 'c', 'state' => 's'], 'invalid_state'],
            'expired' => [['state' => 's', 'connectable' => $valid, 'expires_at' => now()->subMinute()->toIso8601String()], ['code' => 'c', 'state' => 's'], 'invalid_state'],
            'user denied' => [['state' => 's', 'connectable' => $valid, 'expires_at' => $future], ['error' => 'access_denied', 'state' => 's'], 'denied'],
            // The hosted page could not pair: the plan is full. It is its own
            // status for the screen.
            'plan limit' => [['state' => 's', 'connectable' => $valid, 'expires_at' => $future], ['error' => 'plan_limit', 'state' => 's'], 'plan_limit'],
            'unknown error' => [['state' => 's', 'connectable' => $valid, 'expires_at' => $future], ['error' => 'something_new', 'state' => 's'], 'exchange_failed'],
        ];
    }

    protected function assertNothingHappened(): void
    {
        $this->assertCount(0, $this->history);
        $this->assertNull($this->team()->zapmizerConnection);
    }

    public function testE1ACancelledPopupIsDenied()
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['error' => 'access_denied', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "denied"', false);

        $this->assertNothingHappened();
    }

    /** @dataProvider hostedPageEndings */
    #[DataProvider('hostedPageEndings')]
    public function testE2TheHostedPageEndedWithoutACode(string $error, string $message)
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['error' => $error, 'state' => 's']))
            ->assertOk()
            ->assertSee("status: \"{$error}\"", false)
            ->assertSee($message);

        $this->assertNothingHappened();
    }

    public static function hostedPageEndings(): array
    {
        return [
            'plan_limit' => ['plan_limit', 'The Zapmizer plan has no room for another WhatsApp number.'],
            'subscription_required' => ['subscription_required', 'Your Zapmizer account was linked, but it has no active subscription. Connect again to subscribe.'],
        ];
    }

    /** @dataProvider unknownErrors */
    #[DataProvider('unknownErrors')]
    public function testE3AnUnknownErrorIsAnExchangeFailure(string $error)
    {
        Log::spy();
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['error' => $error, 'code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        Log::shouldHaveReceived('warning')->with('zapmizer: unknown connect error.', ['error' => $error])->once();
        $this->assertNothingHappened();
    }

    public static function unknownErrors(): array
    {
        return ['qr_unavailable' => ['qr_unavailable'], 'xyz' => ['xyz']];
    }

    public function testE3AnEmptyErrorIsNoError()
    {
        Log::spy();
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback') . '?error=&code=&state=s')
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        Log::shouldNotHaveReceived('warning');
        $this->assertNothingHappened();
    }

    /** @dataProvider errorsWithABadState */
    #[DataProvider('errorsWithABadState')]
    public function testE4TheStateComesBeforeTheError(array $session, array $query)
    {
        $this->fakeHttp();
        $this->actingAsUser();
        $team = $this->team();
        $session = array_map(fn ($value) => is_callable($value) ? $value($team) : $value, $session);

        $this->withSession([ConnectController::SESSION_KEY => $session])
            ->get(route('zapmizer.connect.callback', $query))
            ->assertOk()
            ->assertSee('status: "invalid_state"', false);

        $this->assertNothingHappened();
    }

    public static function errorsWithABadState(): array
    {
        $valid = fn (Team $team) => Team::class . ':' . $team->id;
        $future = now()->addMinutes(5)->toIso8601String();

        return [
            'expired state' => [['state' => 's', 'connectable' => $valid, 'expires_at' => now()->subMinute()->toIso8601String()], ['error' => 'access_denied', 'state' => 's']],
            'no state in the query' => [['state' => 's', 'connectable' => $valid, 'expires_at' => $future], ['error' => 'access_denied']],
            'another connectable' => [['state' => 's', 'connectable' => 'Other:1', 'expires_at' => $future], ['error' => 'access_denied', 'state' => 's']],
        ];
    }

    /** @dataProvider arrayErrors */
    #[DataProvider('arrayErrors')]
    public function testE5AnArrayErrorIsUnknown(array $query)
    {
        Log::spy();
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', $query))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        Log::shouldHaveReceived('warning')->with('zapmizer: unknown connect error.', ['error' => '(array)'])->once();
        $this->assertNothingHappened();
    }

    public static function arrayErrors(): array
    {
        return [
            'without a code' => [['error' => ['x'], 'state' => 's']],
            'with a code' => [['error' => ['x'], 'state' => 's', 'code' => 'c']],
        ];
    }

    /** @dataProvider arrayStatesAndCodes */
    #[DataProvider('arrayStatesAndCodes')]
    public function testE5AnArrayStateOrCodeNeverAnswers500(array $query, string $status)
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', $query))
            ->assertOk()
            ->assertSee("status: \"{$status}\"", false);

        $this->assertNothingHappened();
    }

    public static function arrayStatesAndCodes(): array
    {
        return [
            'array state' => [['state' => ['x'], 'code' => 'c'], 'invalid_state'],
            'array code' => [['state' => 's', 'code' => ['x']], 'exchange_failed'],
        ];
    }

    public function testT1WithoutAStateInTheQuery()
    {
        $this->fakeHttp();
        $this->actingAsUser();
        $pending = $this->pendingSession();

        $this->withSession($pending)
            ->get(route('zapmizer.connect.callback', ['code' => 'c']))
            ->assertOk()
            ->assertSee('status: "invalid_state"', false);

        $this->assertSame($pending[ConnectController::SESSION_KEY], session(ConnectController::SESSION_KEY));
        $this->assertNothingHappened();
    }

    public function testT4TheSameCallbackTwiceExchangesOnce()
    {
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer());
        $this->actingAsUser();

        $this->startConnect()->assertOk();
        $url = route('zapmizer.connect.callback', ['code' => 'c', 'state' => session(ConnectController::SESSION_KEY)['state']]);

        $this->get($url)->assertOk()->assertSee('status: "ok"', false);
        $this->get($url)->assertOk()->assertSee('status: "invalid_state"', false);

        $this->assertCount(2, $this->history);
    }

    public function testT5WithoutAConnectableTheStateIsSpent()
    {
        $this->fakeHttp();
        $this->actingAsUser();
        $pending = $this->pendingSession();
        config()->set('zapmizer.connect.resolver', ResolvesNothing::class);

        $this->withSession($pending)
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "no_connectable"', false);

        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertCount(0, $this->history);
    }

    public function testT6TheCallbackOfAnOlderTabLeavesTheNewerPending()
    {
        $this->fakeHttp($this->sessionCreated(), $this->sessionCreated(), $this->tokenAnswer());
        $this->actingAsUser();

        $this->startConnect()->assertOk();
        $a = session(ConnectController::SESSION_KEY)['state'];
        $this->startConnect()->assertOk();
        $b = session(ConnectController::SESSION_KEY);

        $this->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => $a]))
            ->assertOk()
            ->assertSee('status: "invalid_state"', false);
        $this->assertSame($b, session(ConnectController::SESSION_KEY));

        $this->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => $b['state']]))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $this->assertNotNull($this->team()->zapmizerConnection);
        $this->assertCount(3, $this->history);
    }

    /** @dataProvider matchingStatesThatDoNotHold */
    #[DataProvider('matchingStatesThatDoNotHold')]
    public function testT7AMatchingStateOfAnotherConnectableOrExpiredIsSpent(array $session)
    {
        $this->fakeHttp();
        $this->actingAsUser();
        $team = $this->team();
        $session = array_map(fn ($value) => is_callable($value) ? $value($team) : $value, $session);

        $this->withSession([ConnectController::SESSION_KEY => $session])
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "invalid_state"', false);

        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertNothingHappened();
    }

    public static function matchingStatesThatDoNotHold(): array
    {
        return [
            'another connectable' => [['state' => 's', 'connectable' => 'Other:1', 'expires_at' => now()->addMinutes(5)->toIso8601String()]],
            'expired' => [['state' => 's', 'connectable' => fn (Team $team) => Team::class . ':' . $team->id, 'expires_at' => now()->subMinute()->toIso8601String()]],
        ];
    }

    public function testAnUnknownErrorIsLoggedCutAt100Characters()
    {
        Log::spy();
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['error' => str_repeat('é', 300), 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        Log::shouldHaveReceived('warning')->with('zapmizer: unknown connect error.', ['error' => str_repeat('é', 100)])->once();
    }

    public function testCallbackReportsAFailedExchange()
    {
        $this->fakeHttp(new Response(404, [], '{"message":"Record not found."}'));
        $this->actingAsUser();

        $this->withSession([ConnectController::SESSION_KEY => [
            'state' => 's', 'connectable' => Team::class . ':' . $this->team()->id, 'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]])->get(route('zapmizer.connect.callback', ['code' => 'used', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertCount(1, $this->history);
    }

    // --- the token of the exchange ---------------------------------------

    private const DELETE_PATH = 'http://zap.test/api/connect/token';

    protected function assertRevoked(string $token): void
    {
        $request = end($this->history)['request'];

        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame(self::DELETE_PATH, (string) $request->getUri());
        $this->assertSame('Bearer ' . $token, $request->getHeaderLine('Authorization'));
    }

    protected function revocationCalls(): int
    {
        return count(array_filter($this->history, fn (array $entry) => $entry['request']->getMethod() === 'DELETE'));
    }

    /**
     * A failed revocation logs `$message` once, with `$context`, the status
     * when the failure had one, and the exception's message.
     */
    protected function assertRevocationWarning(string $message, array $context, ?int $status): void
    {
        Log::shouldHaveReceived('warning')->withArgs(function (string $logged, array $loggedContext = []) use ($message, $context, $status) {
            return $logged === $message
                && array_intersect_key($loggedContext, $context) === $context
                && ($loggedContext['status'] ?? null) === $status
                && is_string($loggedContext['exception'] ?? null);
        })->once();
    }

    /** @dataProvider tokensWithoutANumber */
    #[DataProvider('tokensWithoutANumber')]
    public function testX5ATokenWithoutANumberStoresNothing(array $overrides, bool $fromTheContract)
    {
        $body = $this->tokenPayload($overrides + ['token' => '2|sanctum']);

        if ($fromTheContract) {
            $this->assertMatchesContract('POST', '/connect/token', 200, $body);
        }

        Log::spy();
        $this->fakeHttp(new Response(200, ['Content-Type' => 'application/json'], $body), new Response(204));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertSame(0, ZapmizerConnection::count());
        Log::shouldHaveReceived('warning')->with('zapmizer: the token came without a number.', ['team_id' => 7])->once();
        $this->assertCount(2, $this->history);
        $this->assertRevoked('2|sanctum');
    }

    public static function tokensWithoutANumber(): array
    {
        return [
            'phone_number null' => [['phone_number' => null], false],
            'bot_instance_id null' => [['bot_instance_id' => null], false],
            'both' => [['phone_number' => null, 'bot_instance_id' => null, 'webhook_id' => null, 'webhook_secret' => null], true],
        ];
    }

    public function testX5ATokenWithoutANumberKeepsTheExistingConnection()
    {
        $this->fakeHttp(
            $this->sessionCreated(),
            $this->tokenAnswer(),
            $this->sessionCreated(),
            $this->tokenAnswer(['token' => '2|sanctum', 'bot_instance_id' => null]),
            new Response(204),
        );
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "exchange_failed"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertSame('1|sanctum', $connection->api_token);
        $this->assertSame(9, $connection->bot_instance_id);
        $this->assertTrue($connection->is_active);
        $this->assertRevoked('2|sanctum');
    }

    /** @dataProvider revocationsFromTheContract */
    #[DataProvider('revocationsFromTheContract')]
    public function testX5bTheOrphanTokenIsRevokedFromTheContract(int $status, string $body, ?int $warned)
    {
        $this->assertMatchesContract('DELETE', '/connect/token', $status, $body);
        $this->assertTheOrphanWithoutANumberIsRevoked(new Response($status, $body === '' ? [] : ['Content-Type' => 'application/json'], $body), $warned);
    }

    /** @dataProvider revocationsOutsideTheContract */
    #[DataProvider('revocationsOutsideTheContract')]
    public function testX5bTheOrphanTokenIsRevokedOutsideTheContract(Response $answer, ?int $warned)
    {
        $this->assertTheOrphanWithoutANumberIsRevoked($answer, $warned);
    }

    protected function assertTheOrphanWithoutANumberIsRevoked(Response $answer, ?int $warned): void
    {
        Log::spy();
        $this->fakeHttp($this->tokenAnswer(['token' => '2|sanctum', 'phone_number' => null]), $answer);
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        $this->assertSame(1, $this->revocationCalls());
        $this->assertRevoked('2|sanctum');
        $this->assertOrphanWarning($answer->getStatusCode(), $warned);
    }

    protected function assertOrphanWarning(int $answered, ?int $warned): void
    {
        if (in_array($answered, [204, 401], true)) {
            Log::shouldNotHaveReceived('warning', ['zapmizer: the orphan token was not revoked.', \Mockery::any()]);

            return;
        }

        $this->assertRevocationWarning('zapmizer: the orphan token was not revoked.', ['team_id' => 7], $warned);
    }

    /**
     * [status, body, status in the warning].
     */
    public static function revocationsFromTheContract(): array
    {
        return [
            'revoked 204' => [204, '', null],
            'already gone 401' => [401, '{"message":"Unauthenticated."}', null],
            'not a connect token 404' => [404, '{"message":"No connect grant for this token."}', 404],
        ];
    }

    /**
     * [answer, status in the warning]. A 5xx is ZapmizerUnavailable and a
     * 200 is an unexpected answer: neither has a status.
     */
    public static function revocationsOutsideTheContract(): array
    {
        return [
            '5xx' => [new Response(500, [], ''), null],
            '200 {}' => [new Response(200, ['Content-Type' => 'application/json'], '{}'), null],
        ];
    }

    /** @dataProvider invalidInstanceOrWebhookIdsOutsideTheContract */
    #[DataProvider('invalidInstanceOrWebhookIdsOutsideTheContract')]
    public function testX6AnInvalidIdIsAnExchangeFailure(array $overrides)
    {
        $this->fakeHttp($this->tokenAnswer($overrides));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertCount(1, $this->history);
    }

    public static function invalidInstanceOrWebhookIdsOutsideTheContract(): array
    {
        return [
            'bot_instance_id "abc"' => [['bot_instance_id' => 'abc']],
            'bot_instance_id 0' => [['bot_instance_id' => 0]],
            'bot_instance_id -1' => [['bot_instance_id' => -1]],
            'webhook_id "x"' => [['webhook_id' => 'x']],
        ];
    }

    public function testX6AStringIdInDigitsConnects()
    {
        $this->fakeHttp($this->tokenAnswer(['bot_instance_id' => '12']));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $this->assertSame(12, $this->team()->zapmizerConnection->bot_instance_id);
    }

    public function testX7WithoutAWebhookConnectsWithoutOne()
    {
        $this->fakeHttp($this->tokenAnswer(['webhook_id' => null, 'webhook_secret' => null]));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertTrue($connection->is_active);
        $this->assertNull($connection->webhook_id);
        $this->assertCount(1, $this->history);
    }

    /** @dataProvider tokenRefusalsFromTheContract */
    #[DataProvider('tokenRefusalsFromTheContract')]
    public function testX3ATokenRefusalFromTheContractIsAnExchangeFailure(int $status, string $body)
    {
        $this->assertMatchesContract('POST', '/connect/token', $status, $body);
        $this->assertATokenRefusalIsAnExchangeFailure(new Response($status, ['Content-Type' => 'application/json'], $body));
    }

    public static function tokenRefusalsFromTheContract(): array
    {
        return [
            '422' => [422, '{"message":"The code field is required.","errors":{"code":["The code field is required."]}}'],
        ];
    }

    /** @dataProvider tokenRefusalsOutsideTheContract */
    #[DataProvider('tokenRefusalsOutsideTheContract')]
    public function testX3ATokenRefusalOutsideTheContractIsAnExchangeFailure(Response $answer)
    {
        $this->assertATokenRefusalIsAnExchangeFailure($answer);
    }

    public static function tokenRefusalsOutsideTheContract(): array
    {
        return [
            '401' => [new Response(401, ['Content-Type' => 'application/json'], '{"message":"Unauthenticated."}')],
            '403' => [new Response(403, ['Content-Type' => 'application/json'], '{"message":"Forbidden."}')],
            '409' => [new Response(409, ['Content-Type' => 'application/json'], '{"message":"Conflict."}')],
        ];
    }

    protected function assertATokenRefusalIsAnExchangeFailure(Response $answer): void
    {
        Log::spy();
        $this->fakeHttp($answer);
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "exchange_failed"', false);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'zapmizer: connect callback failed.');
        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertCount(1, $this->history);
    }

    /** @dataProvider revocationsFromTheContract */
    #[DataProvider('revocationsFromTheContract')]
    public function testA2TheOrphanOfATeamConnectedElsewhereIsRevokedFromTheContract(int $status, string $body, ?int $warned)
    {
        $this->assertMatchesContract('DELETE', '/connect/token', $status, $body);
        $this->assertTheOrphanOfATeamConnectedElsewhereIsRevoked(new Response($status, $body === '' ? [] : ['Content-Type' => 'application/json'], $body), $warned);
    }

    /** @dataProvider revocationsOutsideTheContract */
    #[DataProvider('revocationsOutsideTheContract')]
    public function testA2TheOrphanOfATeamConnectedElsewhereIsRevokedOutsideTheContract(Response $answer, ?int $warned)
    {
        $this->assertTheOrphanOfATeamConnectedElsewhereIsRevoked($answer, $warned);
    }

    protected function assertTheOrphanOfATeamConnectedElsewhereIsRevoked(Response $answer, ?int $warned): void
    {
        Log::spy();
        $this->fakeHttp($this->tokenAnswer(['token' => '2|sanctum']), $answer);
        $this->actingAsUser();
        $other = Team::create(['name' => 'Other']);
        $other->zapmizerConnection()->create(['api_token' => 'other-token', 'zapmizer_team_id' => 7, 'is_active' => true]);

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "team_already_connected"', false);

        $this->assertSame(1, $this->revocationCalls());
        $this->assertRevoked('2|sanctum');
        $this->assertSame('other-token', $other->fresh()->zapmizerConnection->api_token);
        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertOrphanWarning($answer->getStatusCode(), $warned);
    }

    public function testA2TheOrphanOfTheUniqueRaceIsRevoked()
    {
        $this->fakeHttp($this->tokenAnswer(['token' => '2|sanctum']), new Response(204));
        $this->actingAsUser();
        $raced = false;
        ZapmizerConnection::creating(function () use (&$raced) {
            if ($raced) {
                return;
            }

            $raced = true;
            Team::create(['name' => 'Other'])->zapmizerConnection()->create(['api_token' => 'other-token', 'zapmizer_team_id' => 7, 'is_active' => true]);
        });

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "team_already_connected"', false);

        $this->assertSame(1, $this->revocationCalls());
        $this->assertRevoked('2|sanctum');
    }

    // --- unreadable values and the previous token ------------------------

    /**
     * The queue of a connect followed by a second one answering `$second`,
     * then `$after` (the revocation of the previous token, when there is
     * one).
     */
    protected function fakeTwoConnects(array $second, Response|\Throwable ...$after): void
    {
        $this->fakeHttp(
            $this->sessionCreated(),
            $this->tokenAnswer(),
            $this->sessionCreated(),
            $this->tokenAnswer($second + ['token' => '2|sanctum']),
            ...$after,
        );
    }

    protected function revokePrevious(mixed $value = true): void
    {
        config()->set('zapmizer.connect.revoke_previous_token', $value);
    }

    /** @dataProvider revocationOptionsOff */
    #[DataProvider('revocationOptionsOff')]
    public function testC3ThePreviousTokenStaysWithTheOptionOff(bool $present, mixed $value)
    {
        if ($present) {
            $this->revokePrevious($value);
        } else {
            config()->set('zapmizer.connect', ['resolver' => ResolvesFirstTeam::class]);
        }

        $this->fakeTwoConnects([]);
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "ok"', false);

        $this->assertCount(4, $this->history);
        $this->assertSame(0, $this->revocationCalls());
        $this->assertSame('2|sanctum', $this->team()->zapmizerConnection->api_token);
    }

    public static function revocationOptionsOff(): array
    {
        return [
            'absent' => [false, null],
            'null' => [true, null],
            'text' => [true, 'abc'],
            'false' => [true, false],
            'env off' => [true, 'off'],
            'env no' => [true, 'no'],
            'env empty' => [true, ''],
        ];
    }

    /** @dataProvider revocationOptionsOn */
    #[DataProvider('revocationOptionsOn')]
    public function testC4ThePreviousTokenIsRevokedWithTheOptionOn(mixed $value, int $status, string $body)
    {
        $this->assertMatchesContract('DELETE', '/connect/token', $status, $body);
        Log::spy();
        $this->revokePrevious($value);
        $this->fakeTwoConnects([], new Response($status, $body === '' ? [] : ['Content-Type' => 'application/json'], $body));
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "ok"', false);

        $this->assertCount(5, $this->history);
        $this->assertSame(1, $this->revocationCalls());
        $this->assertRevoked('1|sanctum');
        $this->assertSame('2|sanctum', $this->team()->zapmizerConnection->api_token);
        Log::shouldNotHaveReceived('warning');
    }

    public static function revocationOptionsOn(): array
    {
        return [
            'true' => [true, 204, ''],
            'env true' => ['true', 204, ''],
            'env 1' => ['1', 204, ''],
            'env on' => ['on', 204, ''],
            'env yes' => ['yes', 204, ''],
            'already gone 401' => [true, 401, '{"message":"Unauthenticated."}'],
        ];
    }

    public function testV1TheFirstConnectRevokesNothing()
    {
        $this->revokePrevious();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer());
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);

        $this->assertCount(2, $this->history);
    }

    /** @dataProvider failedPreviousRevocationsFromTheContract */
    #[DataProvider('failedPreviousRevocationsFromTheContract')]
    public function testV3AFailedRevocationOfThePreviousIsOnlyAWarningFromTheContract(int $status, string $body, ?int $warned)
    {
        $this->assertMatchesContract('DELETE', '/connect/token', $status, $body);
        $this->assertAFailedRevocationOfThePreviousIsOnlyAWarning(new Response($status, ['Content-Type' => 'application/json'], $body), $warned);
    }

    public static function failedPreviousRevocationsFromTheContract(): array
    {
        return [
            'not a connect token 404' => [404, '{"message":"No connect grant for this token."}', 404],
        ];
    }

    /** @dataProvider failedPreviousRevocationsOutsideTheContract */
    #[DataProvider('failedPreviousRevocationsOutsideTheContract')]
    public function testV3AFailedRevocationOfThePreviousIsOnlyAWarningOutsideTheContract(Response|\Throwable $answer, ?int $warned)
    {
        $this->assertAFailedRevocationOfThePreviousIsOnlyAWarning($answer, $warned);
    }

    /**
     * [answer, status in the warning]: a 5xx, the network, a redirect and
     * a 200 have none.
     */
    public static function failedPreviousRevocationsOutsideTheContract(): array
    {
        return [
            'forbidden 403' => [new Response(403, ['Content-Type' => 'application/json'], '{"message":"Forbidden."}'), 403],
            'rate limited 429' => [new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '30'], '{"message":"Too Many Attempts."}'), 429],
            '5xx' => [new Response(500, [], ''), null],
            'network' => [new ConnectException('timed out', new \GuzzleHttp\Psr7\Request('DELETE', 'connect/token')), null],
            'redirect 302' => [new Response(302, ['Location' => 'http://zap.test/login']), null],
            '200 {}' => [new Response(200, ['Content-Type' => 'application/json'], '{}'), null],
        ];
    }

    protected function assertAFailedRevocationOfThePreviousIsOnlyAWarning(Response|\Throwable $answer, ?int $warned): void
    {
        Log::spy();
        $this->revokePrevious();
        $this->fakeTwoConnects([], $answer);
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertSame('2|sanctum', $connection->api_token);
        $this->assertRevoked('1|sanctum');
        $this->assertRevocationWarning('zapmizer: the previous token was not revoked.', ['connection_id' => $connection->id], $warned);
    }

    public function testV4TheSameTokenIsNotRevoked()
    {
        $this->revokePrevious();
        $this->fakeTwoConnects(['token' => '1|sanctum']);
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "ok"', false);

        $this->assertCount(4, $this->history);
    }

    /**
     * Exactly `$warnings` warnings: [message, context] each, once.
     */
    protected function assertExactlyTheseWarnings(array $warnings): void
    {
        Log::shouldHaveReceived('warning')->times(count($warnings));

        foreach ($warnings as [$message, $context]) {
            Log::shouldHaveReceived('warning')->with($message, $context)->once();
        }
    }

    protected function columnWarnings(int $id, string ...$columns): array
    {
        return array_map(fn (string $column) => ['zapmizer: unreadable stored value, overwritten.', ['connection_id' => $id, 'column' => $column]], $columns);
    }

    public function testV5AnUnreadablePreviousTokenIsSkipped()
    {
        Log::spy();
        $this->revokePrevious();
        $this->fakeTwoConnects(['webhook_secret' => 'whsec_2']);
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->swapAppKey();
        $this->connect()->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertCount(4, $this->history);
        $this->assertExactlyTheseWarnings([
            ['zapmizer: could not read the previous token to revoke it.', ['connection_id' => $connection->id]],
            ...$this->columnWarnings($connection->id, 'api_token', 'webhook_secret'),
        ]);
        $this->assertSame('2|sanctum', $connection->fresh()->api_token);
        $this->assertSame('whsec_2', $connection->fresh()->webhook_secret);
    }

    public function testV6TheWebhookFailedConnectAlsoRevokes()
    {
        $this->revokePrevious();
        $this->fakeTwoConnects(['webhook_id' => 43, 'webhook_secret' => null], new Response(204));
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->connect()->assertSee('status: "webhook_failed"', false);

        $this->assertCount(5, $this->history);
        $this->assertRevoked('1|sanctum');
    }

    public function testV8TheAppKeyChangedWithTheOptionOff()
    {
        Log::spy();
        $this->fakeTwoConnects(['webhook_secret' => 'whsec_2']);
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->swapAppKey();
        $this->connect()->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertCount(4, $this->history);
        $this->assertExactlyTheseWarnings($this->columnWarnings($connection->id, 'api_token', 'webhook_secret'));
        $this->assertSame('2|sanctum', $connection->fresh()->api_token);
        $this->assertSame('whsec_2', $connection->fresh()->webhook_secret);
    }

    public function testW3bAnotherTeamWithTheAppKeyChanged()
    {
        $this->assertANewWebhookWithTheAppKeyChanged(['team_id' => 8, 'team_name' => 'Other', 'webhook_id' => 77, 'webhook_secret' => 'whsec_77']);
    }

    public function testW4bANewWebhookWithTheAppKeyChanged()
    {
        $this->assertANewWebhookWithTheAppKeyChanged(['webhook_id' => 43, 'webhook_secret' => 'whsec_43']);
    }

    /**
     * A connect, the APP_KEY changed, then a connect with a new webhook and
     * its secret: both unreadable columns are reset and overwritten.
     */
    protected function assertANewWebhookWithTheAppKeyChanged(array $second): void
    {
        Log::spy();
        $this->fakeHttp(
            $this->sessionCreated(),
            $this->tokenAnswer(['webhook_secret' => 'whsec_1']),
            $this->sessionCreated(),
            $this->tokenAnswer($second + ['token' => '2|sanctum']),
        );
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->swapAppKey();
        $this->connect()->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection->fresh();
        $this->assertExactlyTheseWarnings($this->columnWarnings($connection->id, 'api_token', 'webhook_secret'));
        $this->assertSame('2|sanctum', $connection->api_token);
        $this->assertSame($second['webhook_secret'], $connection->webhook_secret);
        $this->assertSame($second['team_id'] ?? 7, $connection->zapmizer_team_id);
        $this->assertSame($second['webhook_id'], $connection->webhook_id);
        $this->assertNull(DB::table('zapmizer_connections')->value('webhook_previous_secret'));
        $this->assertSame(1, ZapmizerConnection::count());
        $this->assertNoWebhookCalls();
    }

    public function testW5AnUnreadableStoredSecretIsNotReused()
    {
        Log::spy();
        $this->fakeHttp(
            $this->sessionCreated(),
            $this->tokenAnswer(['webhook_secret' => 'whsec_1']),
            $this->sessionCreated(),
            $this->tokenAnswer(['token' => '2|sanctum', 'webhook_secret' => null]),
        );
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $this->swapAppKey();
        $this->connect()->assertSee('status: "webhook_failed"', false);

        $connection = $this->team()->zapmizerConnection->fresh();
        $this->assertTrue($connection->is_active);
        $this->assertNull($connection->webhook_secret);
        $this->assertSame('2|sanctum', $connection->api_token);
        $this->assertExactlyTheseWarnings([
            ['zapmizer: unreadable webhook secret.', ['connection_id' => $connection->id]],
            ...$this->columnWarnings($connection->id, 'api_token'),
            ['zapmizer: connected without a webhook secret.', ['connection_id' => $connection->id, 'webhook_id' => 42]],
        ]);
        $this->assertNoWebhookCalls();
    }

    public function testA1TheRefusedTeamDoesNotRevokeThePrevious()
    {
        $this->revokePrevious();
        $this->fakeTwoConnects(['team_id' => 8, 'team_name' => 'Other'], new Response(204));
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        Team::create(['name' => 'Other'])->zapmizerConnection()->create(['api_token' => 'other-token', 'zapmizer_team_id' => 8, 'is_active' => true]);
        $this->connect()->assertSee('status: "team_already_connected"', false);

        $this->assertSame(1, $this->revocationCalls());
        $this->assertRevoked('2|sanctum');
        $this->assertSame('1|sanctum', $this->team()->zapmizerConnection->api_token);
    }

    public function testD5AnUnreadableTokenIsDeletedWithoutACall()
    {
        Log::spy();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer());
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $id = $this->team()->zapmizerConnection->id;
        $this->swapAppKey();

        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk()->assertExactJson(['status' => 'disconnected']);

        $this->assertSame(0, ZapmizerConnection::count());
        $this->assertCount(2, $this->history);
        $this->assertExactlyTheseWarnings([['zapmizer: could not read the token to revoke it.', ['connection_id' => $id]]]);
    }

    public function testL1ShowLiveWithAnUnreadableTokenIsReauthRequired()
    {
        Log::spy();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer());
        $this->actingAsUser();

        $this->connect()->assertSee('status: "ok"', false);
        $id = $this->team()->zapmizerConnection->id;
        $this->swapAppKey();

        $this->getJson(route('zapmizer.connect.show', ['live' => 1]))
            ->assertOk()
            ->assertJsonPath('connection.state', 'reauth_required')
            ->assertJsonPath('connection.is_online', false);

        $this->assertCount(2, $this->history);
        $this->assertExactlyTheseWarnings([['zapmizer: could not read the token to query the instance.', ['connection_id' => $id]]]);
    }

    // --- 429 on the exchange ---------------------------------------------

    private const TOO_MANY = '{"message":"Too Many Attempts."}';

    protected function tooMany(array $headers = ['Retry-After' => '20']): Response
    {
        return new Response(429, ['Content-Type' => 'application/json'] + $headers, self::TOO_MANY);
    }

    protected function callbackWith(array $query = ['code' => 'c', 'state' => 's']): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('zapmizer.connect.callback', $query));
    }

    public function testR1A429OnTheExchangeWaits()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->assertMatchesContract('POST', '/connect/token', 429, self::TOO_MANY, ['Retry-After' => '20']);
        Log::spy();
        $this->fakeHttp($this->tooMany());
        $this->actingAsUser();
        $pending = $this->pendingSession();

        $this->withSession($pending)->callbackWith()
            ->assertOk()
            ->assertSee('<meta http-equiv="refresh" content="21">', false)
            ->assertSee('Zapmizer is busy. This window tries again in 21 seconds.')
            ->assertDontSee('postMessage', false);

        $this->assertSame($pending[ConnectController::SESSION_KEY] + [
            'code_seen_at' => now()->getTimestamp(),
            'exchange_attempts' => 1,
            'code_hash' => hash('sha256', 'c'),
        ], session(ConnectController::SESSION_KEY));
        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertCount(1, $this->history);
        Log::shouldNotHaveReceived('error');
    }

    public function testR2TheRetryAfterTheWaitConnectsAndKeepsTheWebhookKey()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->app->bind(ConnectController::class, KeyedConnectController::class);
        $this->fakeHttp($this->sessionCreated(), $this->tooMany(), $this->tokenAnswer());
        $this->actingAsUser();

        $this->startConnect()->assertOk();
        $url = route('zapmizer.connect.callback', ['code' => 'c', 'state' => session(ConnectController::SESSION_KEY)['state']]);

        $this->get($url)->assertOk()->assertSee('content="21"', false);
        $this->assertSame('fixed-key', session(ConnectController::SESSION_KEY)['webhook_key']);

        $this->travel(21)->seconds();
        $this->get($url)->assertOk()->assertSee('status: "ok"', false);

        $this->assertSame('fixed-key', KeyedConnectController::$storedWith);
        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertNotNull($this->team()->zapmizerConnection);
        $this->assertCount(3, $this->history);
    }

    /** @dataProvider unusableRetryAfters */
    #[DataProvider('unusableRetryAfters')]
    public function testR3WithoutAUsableRetryAfterWaitsTen(array $headers)
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->fakeHttp($this->tooMany($headers));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())->callbackWith()
            ->assertOk()
            ->assertSee('<meta http-equiv="refresh" content="10">', false);
    }

    public static function unusableRetryAfters(): array
    {
        return [
            'without the header' => [[]],
            'an HTTP date' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']],
        ];
    }

    public function testR4AWaitPastTheWindowIsRateLimited()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->fakeHttp($this->tooMany(['Retry-After' => '300']));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())->callbackWith()
            ->assertOk()
            ->assertSee('status: "rate_limited"', false)
            ->assertSee('Zapmizer is busy right now. Close this window and connect again in a minute.');

        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertNull($this->team()->zapmizerConnection);
    }

    public function testR5TheSixthAttemptIsRateLimited()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->fakeHttp(...array_fill(0, 6, $this->tooMany(['Retry-After' => '1'])));
        $this->actingAsUser();
        $this->withSession($this->pendingSession());

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->callbackWith()->assertOk()->assertSee('<meta http-equiv="refresh" content="2">', false);
            $this->assertSame($attempt, session(ConnectController::SESSION_KEY)['exchange_attempts']);
            $this->travel(2)->seconds();
        }

        $this->callbackWith()->assertOk()->assertSee('status: "rate_limited"', false);

        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertCount(6, $this->history);
    }

    public function testR6AWaitPastTheStateIsRateLimited()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        config()->set('zapmizer.connect.state_ttl_minutes', 1);
        $this->fakeHttp($this->sessionCreated(), $this->tooMany(['Retry-After' => '60']));
        $this->actingAsUser();

        $this->startConnect()->assertOk();

        $this->callbackWith(['code' => 'c', 'state' => session(ConnectController::SESSION_KEY)['state']])
            ->assertOk()
            ->assertSee('status: "rate_limited"', false);

        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertCount(2, $this->history);
    }

    public function testR7AnErrorNeverReachesTheExchange()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->fakeHttp($this->tooMany());
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->callbackWith(['error' => 'access_denied', 'code' => 'c', 'state' => 's'])
            ->assertOk()
            ->assertSee('status: "denied"', false);
        $this->assertCount(0, $this->history);

        // After a wait, the code_hash is checked before the error (D3): an
        // `?error=` without the code is not the popup that waited.
        $this->withSession($this->pendingSession())->callbackWith()->assertOk()->assertSee('content="21"', false);
        $this->callbackWith(['error' => 'access_denied', 'state' => 's'])
            ->assertOk()
            ->assertSee('status: "invalid_state"', false);

        $this->assertCount(1, $this->history);
    }

    public function testR9AnotherCodeAfterAWaitIsInvalidState()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->fakeHttp($this->tooMany());
        $this->actingAsUser();

        $this->withSession($this->pendingSession())->callbackWith()->assertOk()->assertSee('content="21"', false);
        $this->callbackWith(['code' => 'other', 'state' => 's'])
            ->assertOk()
            ->assertSee('status: "invalid_state"', false);

        $this->assertCount(1, $this->history);
        $this->assertNull($this->team()->zapmizerConnection);
    }

    public function testR10ARetryAnswered404IsAnExchangeFailure()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->fakeHttp($this->tooMany(['Retry-After' => '1']), new Response(404, ['Content-Type' => 'application/json'], '{"message":"Record not found."}'));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())->callbackWith()->assertOk()->assertSee('content="2"', false);
        $this->travel(2)->seconds();
        $this->callbackWith()->assertOk()->assertSee('status: "exchange_failed"', false);

        $this->assertNull($this->team()->zapmizerConnection);
        $this->assertCount(2, $this->history);
    }

    public function testARetryAfterOfZeroWaitsOneSecond()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->fakeHttp($this->tooMany(['Retry-After' => '0']));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())->callbackWith()
            ->assertOk()
            ->assertSee('<meta http-equiv="refresh" content="1">', false);
    }

    public function testAWaitingPopupLosesToANewStart()
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->fakeHttp($this->sessionCreated(), $this->tooMany(), $this->sessionCreated());
        $this->actingAsUser();

        $this->startConnect()->assertOk();
        $a = route('zapmizer.connect.callback', ['code' => 'c', 'state' => session(ConnectController::SESSION_KEY)['state']]);
        $this->get($a)->assertOk()->assertSee('content="21"', false);

        $this->startConnect()->assertOk();
        $b = session(ConnectController::SESSION_KEY);

        $this->get($a)->assertOk()->assertSee('status: "invalid_state"', false);

        $this->assertSame($b, session(ConnectController::SESSION_KEY));
        $this->assertArrayNotHasKey('code_seen_at', $b);
        $this->assertCount(3, $this->history);
    }

    // --- destroy --------------------------------------------------------

    /** @dataProvider destroyRevocationsFromTheContract */
    #[DataProvider('destroyRevocationsFromTheContract')]
    public function testD1DestroyRevokesAndDeletes(int $status, string $body)
    {
        $this->assertMatchesContract('DELETE', '/connect/token', $status, $body);
        Log::spy();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer(), new Response($status, $body === '' ? [] : ['Content-Type' => 'application/json'], $body));
        $this->actingAsUser();
        $this->connect()->assertSee('status: "ok"', false);

        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk()->assertExactJson(['status' => 'disconnected']);

        $this->assertSame(0, ZapmizerConnection::count());
        $this->assertCount(3, $this->history);
        $this->assertRevoked('1|sanctum');
        Log::shouldNotHaveReceived('warning');
        $this->assertNoWebhookCalls();
    }

    public static function destroyRevocationsFromTheContract(): array
    {
        return [
            'revoked 204' => [204, ''],
            'already gone 401' => [401, '{"message":"Unauthenticated."}'],
        ];
    }

    /** @dataProvider failedDestroyRevocationsFromTheContract */
    #[DataProvider('failedDestroyRevocationsFromTheContract')]
    public function testD2AFailedRevocationStillDeletesFromTheContract(int $status, string $body, string $message, ?int $warned)
    {
        $this->assertMatchesContract('DELETE', '/connect/token', $status, $body);
        $this->assertAFailedRevocationStillDeletes(new Response($status, ['Content-Type' => 'application/json'], $body), $message, $warned);
    }

    public static function failedDestroyRevocationsFromTheContract(): array
    {
        return [
            'not a connect token 404' => [404, '{"message":"No connect grant for this token."}', 'zapmizer: the token was not revoked and is still valid.', 404],
        ];
    }

    /** @dataProvider failedDestroyRevocationsOutsideTheContract */
    #[DataProvider('failedDestroyRevocationsOutsideTheContract')]
    public function testD2AFailedRevocationStillDeletesOutsideTheContract(Response|\Throwable $answer, ?int $warned)
    {
        $this->assertAFailedRevocationStillDeletes($answer, 'zapmizer: the token was not revoked.', $warned);
    }

    /**
     * [answer, status in the warning]: a 5xx, the network and a 200 have
     * none.
     */
    public static function failedDestroyRevocationsOutsideTheContract(): array
    {
        return [
            'forbidden 403' => [new Response(403, ['Content-Type' => 'application/json'], '{"message":"Forbidden."}'), 403],
            '5xx' => [new Response(500, [], ''), null],
            'network' => [new ConnectException('timed out', new \GuzzleHttp\Psr7\Request('DELETE', 'connect/token')), null],
            '200 {}' => [new Response(200, ['Content-Type' => 'application/json'], '{}'), null],
        ];
    }

    protected function assertAFailedRevocationStillDeletes(Response|\Throwable $answer, string $message, ?int $warned): void
    {
        Log::spy();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer(), $answer);
        $this->actingAsUser();
        $this->connect()->assertSee('status: "ok"', false);
        $id = $this->team()->zapmizerConnection->id;

        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk()->assertExactJson(['status' => 'disconnected']);

        $this->assertSame(0, ZapmizerConnection::count());
        $this->assertCount(3, $this->history);
        $this->assertRevoked('1|sanctum');
        $this->assertRevocationWarning($message, ['connection_id' => $id], $warned);
        $this->assertNoWebhookCalls();
    }

    public function testD3NothingToRevokeCallsNothing()
    {
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer());
        $this->actingAsUser();

        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk()->assertExactJson(['status' => 'disconnected']);
        $this->assertCount(0, $this->history);

        $this->connect()->assertSee('status: "ok"', false);
        $this->team()->zapmizerConnection->forceFill(['api_token' => null])->save();

        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk()->assertExactJson(['status' => 'disconnected']);

        $this->assertSame(0, ZapmizerConnection::count());
        $this->assertCount(2, $this->history);
        $this->assertNoWebhookCalls();
    }

    // --- resolver -------------------------------------------------------

    public function testAResolverReturningANonConnectableFailsNamingTheClass()
    {
        $this->fakeHttp();
        config()->set('zapmizer.connect.resolver', ResolvesPlainModel::class);
        $this->actingAsUser();
        $this->withoutExceptionHandling();

        // The contract is the return type: no method_exists deep in the
        // controller, the resolver itself refuses to hand the model over.
        try {
            $this->getJson(route('zapmizer.connect.show'));
            $this->fail('Expected TypeError.');
        } catch (\TypeError $exception) {
            $this->assertStringContainsString('Contracts\\Connectable', $exception->getMessage());
            $this->assertStringContainsString(PlainModel::class, $exception->getMessage());
        }
    }

    public function testAResolverWithNothingToConnectAnswers403WithAStableCode()
    {
        $this->fakeHttp();
        config()->set('zapmizer.connect.resolver', ResolvesNothing::class);
        $this->actingAsUser();

        $this->getJson(route('zapmizer.connect.show'))
            ->assertForbidden()
            ->assertExactJson(['code' => 'no_connectable']);

        $this->postJson(route('zapmizer.connect.start'))
            ->assertForbidden()
            ->assertExactJson(['code' => 'no_connectable']);
    }

    public function testCallbackReportsNothingToConnectOnTheResultPage()
    {
        $this->fakeHttp();
        config()->set('zapmizer.connect.resolver', ResolvesNothing::class);
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "no_connectable"', false)
            ->assertSee('window.opener.postMessage', false);
    }

    public function testDefaultResolverThrowsNoConnectableWithoutAUser()
    {
        $this->expectException(NoConnectableException::class);

        (new ResolvesAuthenticatedUser())->resolve(Request::create('/zapmizer/connect'));
    }

    public function testDefaultResolverRefusesAUserWithoutTheTrait()
    {
        $this->fakeHttp();
        config()->set('zapmizer.connect.resolver', ResolvesAuthenticatedUser::class);
        $this->actingAs(PlainUser::create(['name' => 'Plain']));
        $this->withoutExceptionHandling();

        try {
            $this->getJson(route('zapmizer.connect.show'));
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertStringContainsString(PlainUser::class, $exception->getMessage());
            $this->assertStringContainsString('Contracts\\Connectable', $exception->getMessage());
        }
    }

    public function testDefaultResolverConnectsTheAuthenticatedUser()
    {
        $this->fakeHttp(new Response(200, [], $this->tokenPayload()));
        // The resolver is read from config at resolve time, so a runtime
        // change is honoured (defineEnvironment() wins over the attribute).
        config()->set('zapmizer.connect.resolver', ResolvesAuthenticatedUser::class);
        $user = $this->actingAsUser();

        $this->withSession([ConnectController::SESSION_KEY => [
            'state' => 's', 'connectable' => User::class . ':' . $user->id, 'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]])->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))->assertOk()->assertSee('status: "ok"', false);

        $this->assertTrue(ZapmizerConnection::sole()->connectable->is($user));
        $this->assertEquals(0, Team::count());
    }
}
