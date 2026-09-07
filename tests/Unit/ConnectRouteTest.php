<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Connect\ResolvesAuthenticatedUser;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\PlainModel;
use NotificationChannels\Zapmizer\Test\Fixtures\PlainUser;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesFirstTeam;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesNothing;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesPlainModel;
use NotificationChannels\Zapmizer\Test\Fixtures\Team;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConnectRouteTest extends TestCase
{
    use CreatesConnectionTables;

    /** @var array<int, array{request: \GuzzleHttp\Psr7\Request}> */
    protected array $history = [];

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.base_uri', 'http://zap.test/api/');
        $app['config']->set('zapmizer.partner.id', 'partner-id');
        $app['config']->set('zapmizer.partner.secret', 'partner-secret');
        // The resolver connects the user's team, as a multi-tenant app would.
        $app['config']->set('zapmizer.connect.resolver', ResolvesFirstTeam::class);
    }

    protected function fakeHttp(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $this->instance(HttpClient::class, new HttpClient(['handler' => $stack]));
    }

    protected function actingAsUser(): User
    {
        $user = User::create(['name' => 'Test']);
        $this->actingAs($user);

        return $user;
    }

    protected function team(): Team
    {
        return Team::firstOrCreate(['name' => 'Acme']);
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

    protected function connectedPayload(int $id = 9, string $number = '5581911110000'): string
    {
        return json_encode(['data' => [
            'id' => $id, 'state' => 'connected', 'state_label' => 'Conectado', 'is_online' => true, 'is_up' => true,
            'qrcode' => null, 'qrcode_available_at' => null, 'qrcode_expires_at' => null, 'number' => $number,
        ]]);
    }

    protected function qrPayload(int $id = 9): string
    {
        return json_encode(['data' => [
            'id' => $id, 'state' => 'qrcode', 'state_label' => 'Aguardando QR', 'is_online' => false, 'is_up' => true,
            'qrcode' => 'QR-DATA', 'qrcode_available_at' => now()->toIso8601String(), 'qrcode_expires_at' => now()->addMinute()->toIso8601String(), 'number' => null,
        ]]);
    }

    protected function statePayload(int $id, string $state): string
    {
        return json_encode(['data' => [
            'id' => $id, 'state' => $state, 'state_label' => $state, 'is_online' => false, 'is_up' => false,
            'qrcode' => null, 'qrcode_available_at' => null, 'qrcode_expires_at' => null, 'number' => null,
        ]]);
    }

    protected function webhookPayload(): string
    {
        return json_encode(['data' => ['id' => 42, 'secret' => 'whsec_new']]);
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
        foreach (['show', 'destroy', 'start', 'callback', 'instance', 'instances', 'connection'] as $name) {
            $route = Route::getRoutes()->getByName("zapmizer.connect.{$name}");

            $this->assertNotNull($route, "zapmizer.connect.{$name}");
            $this->assertContains('auth', $route->gatherMiddleware(), "zapmizer.connect.{$name}");
        }

        $this->assertEquals('zapmizer/connect/callback', Route::getRoutes()->getByName('zapmizer.connect.callback')->uri());
    }

    public function testShowReturnsTheConnectionState()
    {
        $this->actingAsUser();

        $this->getJson(route('zapmizer.connect.show'))->assertOk()->assertExactJson(['connection' => null]);

        $this->authorizedTeam();

        $response = $this->getJson(route('zapmizer.connect.show'))->assertOk();
        $response->assertJsonPath('connection.is_active', false);
        $response->assertJsonPath('connection.api_token_masked', '••••oken');
        $response->assertJsonMissingPath('connection.api_token');
    }

    // --- start ----------------------------------------------------------

    public function testStartStoresStateAndReturnsThePopupUrl()
    {
        $this->fakeHttp(new Response(201, [], json_encode(['url' => 'http://zap.test/connect/1?s=abc', 'expires_at' => '2026-09-07T01:00:00Z'])));
        $this->actingAsUser();

        $this->postJson(route('zapmizer.connect.start'))
            ->assertOk()
            ->assertExactJson(['url' => 'http://zap.test/connect/1?s=abc', 'expires_at' => '2026-09-07T01:00:00Z']);

        $pending = session(ConnectController::SESSION_KEY);
        $this->assertEquals(40, strlen($pending['state']));
        $this->assertEquals(Team::class . ':' . $this->team()->id, $pending['connectable']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertEquals('http://localhost/zapmizer/connect/callback', $body['redirect_uri']);
        $this->assertEquals($pending['state'], $body['state']);
        $this->assertEquals('partner-id|partner-secret', $this->history[0]['request']->getHeaderLine('X-Partner-Key'));
    }

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

    public function testGuestsAreRejected()
    {
        Route::get('login', fn () => 'login')->name('login');

        $this->post(route('zapmizer.connect.start'))->assertRedirect(route('login'));
    }

    // --- callback -------------------------------------------------------

    public function testCallbackExchangesTheCodeAndStoresAnInactiveConnection()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['token' => '1|sanctum', 'team_id' => 7, 'team_name' => 'Acme'])));
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
        $this->assertFalse($connection->is_active);
        $this->assertNull($connection->phone_number);

        // The state is single-use.
        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertEquals(['code' => '12.secret'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testCallbackOnReconnectKeepsThePairedNumberAndResetsConnectedAt()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['token' => '2|sanctum', 'team_id' => 7, 'team_name' => 'Acme'])));
        $this->actingAsUser();
        $this->authorizedTeam(['phone_number' => '5581911110000', 'bot_instance_id' => 9, 'connected_at' => now(), 'is_active' => true]);

        $this->withSession([ConnectController::SESSION_KEY => [
            'state' => 's', 'connectable' => Team::class . ':' . $this->team()->id, 'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]])->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))->assertOk();

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(1, ZapmizerConnection::count());
        $this->assertEquals('2|sanctum', $connection->api_token);
        $this->assertEquals('5581911110000', $connection->phone_number);
        $this->assertEquals(9, $connection->bot_instance_id);
        $this->assertNull($connection->connected_at);
    }

    public function testCallbackOnAnotherTeamForgetsThePairingAndDeletesTheOldWebhook()
    {
        Log::spy();
        $this->fakeHttp(
            new Response(200, [], json_encode(['token' => '2|sanctum', 'team_id' => 8, 'team_name' => 'Other'])),
            new Response(200, [], json_encode(['message' => 'Webhook removido.'])),
        );
        $this->actingAsUser();
        $this->authorizedTeam([
            'phone_number' => '5581911110000', 'bot_instance_id' => 9, 'connected_at' => now(), 'is_active' => true,
            'webhook_id' => 42, 'webhook_secret' => 'whsec_old', 'webhook_previous_secret' => 'whsec_older',
        ]);

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(1, ZapmizerConnection::count());
        $this->assertEquals('2|sanctum', $connection->api_token);
        $this->assertEquals(8, $connection->zapmizer_team_id);
        $this->assertEquals('Other', $connection->zapmizer_team_name);
        // Nothing of the old team survives: number, instance, webhook, activity.
        $this->assertNull($connection->phone_number);
        $this->assertNull($connection->bot_instance_id);
        $this->assertNull($connection->connected_at);
        $this->assertNull($connection->webhook_id);
        $this->assertNull($connection->webhook_secret);
        $this->assertNull($connection->webhook_previous_secret);
        $this->assertFalse($connection->is_active);

        // The old webhook is deleted with the OLD token.
        $delete = $this->history[1]['request'];
        $this->assertEquals('DELETE', $delete->getMethod());
        $this->assertEquals('http://zap.test/api/webhooks/42', (string) $delete->getUri());
        $this->assertEquals('Bearer team-token', $delete->getHeaderLine('Authorization'));
        Log::shouldNotHaveReceived('warning');
    }

    public function testCallbackOnAnotherTeamSurvivesAFailedRemoteDeletion()
    {
        Log::spy();
        $this->fakeHttp(
            new Response(200, [], json_encode(['token' => '2|sanctum', 'team_id' => 8, 'team_name' => 'Other'])),
            new Response(401, [], '{}'),
        );
        $this->actingAsUser();
        $this->authorizedTeam(['phone_number' => '5581911110000', 'bot_instance_id' => 9, 'is_active' => true, 'webhook_id' => 42, 'webhook_secret' => 'whsec_old']);

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $this->assertEquals(8, $this->team()->zapmizerConnection->zapmizer_team_id);
        $this->assertNull($this->team()->zapmizerConnection->webhook_id);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'could not delete the webhook'));
    }

    public function testCallbackRefusesATeamAlreadyConnectedElsewhere()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['token' => '2|sanctum', 'team_id' => 7, 'team_name' => 'Acme'])));
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

    public function testCallbackRefusesSwitchingToATeamConnectedElsewhere()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['token' => '2|sanctum', 'team_id' => 8, 'team_name' => 'Other'])));
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

    #[DataProvider('exchangeFailures')]
    public function testCallbackNeverAnswersA500ToThePopup(Response $response)
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
            'partner key refused' => [new Response(401, [], '{}')],
            'zapmizer down' => [new Response(502, [], '')],
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
        ];
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
    }

    // --- instance -------------------------------------------------------

    public function testInstanceRequiresAnAuthorizedConnection()
    {
        $this->actingAsUser();

        $this->postJson(route('zapmizer.connect.instance'))->assertStatus(409)->assertExactJson(['code' => 'not_connected']);
        $this->getJson(route('zapmizer.connect.instances'))->assertStatus(409)->assertExactJson(['code' => 'not_connected']);
        $this->getJson(route('zapmizer.connect.connection'))->assertStatus(409)->assertExactJson(['code' => 'no_instance']);
    }

    public function testInstanceAdoptsTheOnlyConnectedOneActivatesAndRegistersTheWebhook()
    {
        $this->fakeHttp(
            new Response(200, [], json_encode(['data' => [['id' => 9, 'client' => ['cid_formatted' => '+55 81 91111-0000']]]])),
            new Response(200, [], $this->connectedPayload(9)),
            new Response(201, [], $this->webhookPayload()),
        );
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->postJson(route('zapmizer.connect.instance'))
            ->assertOk()
            ->assertJsonPath('connection.state', 'connected')
            ->assertJsonPath('connection.number', '5581911110000');

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(9, $connection->bot_instance_id);
        $this->assertEquals('5581911110000', $connection->phone_number);
        $this->assertTrue($connection->is_active);
        $this->assertNotNull($connection->connected_at);
        $this->assertEquals(42, $connection->webhook_id);
        $this->assertEquals('whsec_new', $connection->webhook_secret);
        $this->assertTrue($this->team()->hasActiveZapmizer());

        $this->assertEquals('http://zap.test/api/bot-instances?connected=1&per_page=100', (string) $this->history[0]['request']->getUri());
        $this->assertEquals('Bearer team-token', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertEquals(
            ['url' => 'http://localhost/zapmizer/webhook', 'enabled' => true],
            json_decode((string) $this->history[2]['request']->getBody(), true)
        );
    }

    public function testInstanceAsksForAChoiceWhenTwoAreConnected()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['data' => [
            ['id' => 9, 'client' => ['cid_formatted' => '+55 81 91111-0000']],
            ['id' => 10, 'client' => ['cid_formatted' => '+55 81 92222-0000']],
            ['id' => 11, 'client' => []], // no paired number: never an option
        ]])));
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => null]);

        $this->postJson(route('zapmizer.connect.instance'))
            ->assertOk()
            ->assertExactJson(['code' => 'choice_required', 'instances' => [
                ['id' => 9, 'number' => '+55 81 91111-0000', 'is_current' => false],
                ['id' => 10, 'number' => '+55 81 92222-0000', 'is_current' => false],
            ]]);

        $this->assertNull($this->team()->zapmizerConnection->bot_instance_id);
    }

    public function testInstanceCreatesOneWhenNoneIsConnected()
    {
        $this->fakeHttp(
            new Response(200, [], json_encode(['data' => []])),
            new Response(201, [], json_encode(['data' => ['id' => 12]])),
            new Response(200, [], $this->qrPayload(12)),
        );
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->postJson(route('zapmizer.connect.instance'))
            ->assertOk()
            ->assertJsonPath('connection.state', 'qrcode')
            ->assertJsonPath('connection.qrcode', 'QR-DATA');

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(12, $connection->bot_instance_id);
        $this->assertFalse($connection->is_active);
        $this->assertEquals('POST', $this->history[1]['request']->getMethod());
        $this->assertEquals('http://zap.test/api/bot-instances', (string) $this->history[1]['request']->getUri());
    }

    public function testInstanceReusesTheStoredInstanceInsteadOfCreatingAnother()
    {
        $this->fakeHttp(new Response(200, [], $this->qrPayload(9)));
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => 9]);

        $this->postJson(route('zapmizer.connect.instance'))->assertOk()->assertJsonPath('connection.id', 9);

        $this->assertCount(1, $this->history);
        $this->assertEquals('http://zap.test/api/bot-instances/9/connection', (string) $this->history[0]['request']->getUri());
    }

    #[DataProvider('createFailures')]
    public function testInstanceCreateMapsZapmizerErrors(Response $response, int $status, array $json)
    {
        $this->fakeHttp($response);
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->postJson(route('zapmizer.connect.instance'), ['create' => true])
            ->assertStatus($status)
            ->assertJson($json);
    }

    public static function createFailures(): array
    {
        return [
            'plan limit' => [new Response(402, [], json_encode(['message' => 'Plan limit reached.'])), 422, ['code' => 'plan_limit', 'message' => 'Plan limit reached.']],
            'booting' => [new Response(423, [], ''), 202, ['code' => 'booting']],
            'token revoked' => [new Response(401, [], '{}'), 200, ['code' => 'reauth_required']],
            'zapmizer down' => [new Response(500, [], ''), 503, ['code' => 'zapmizer_unavailable']],
        ];
    }

    public function testInstanceStoresTheBootingIdFromA423SoTheRetryDoesNotCreateAnother()
    {
        $this->fakeHttp(
            new Response(200, [], json_encode(['data' => []])),
            new Response(423, [], json_encode(['message' => 'Already booting.', 'bot_instance_id' => 77])),
            // The retry: the stored instance is polled, not created again.
            new Response(200, [], $this->qrPayload(77)),
        );
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->postJson(route('zapmizer.connect.instance'))->assertStatus(202)->assertExactJson(['code' => 'booting']);
        $this->assertEquals(77, $this->team()->zapmizerConnection->bot_instance_id);

        $this->postJson(route('zapmizer.connect.instance'))->assertOk()->assertJsonPath('connection.id', 77);
        $this->assertCount(3, $this->history);
        $this->assertEquals('http://zap.test/api/bot-instances/77/connection', (string) $this->history[2]['request']->getUri());
    }

    public function testInstanceCreateStoresTheBootingIdEvenWhenCreateWasExplicit()
    {
        $this->fakeHttp(new Response(423, [], json_encode(['bot_instance_id' => 78])));
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->postJson(route('zapmizer.connect.instance'), ['create' => true])->assertStatus(202);

        $this->assertEquals(78, $this->team()->zapmizerConnection->bot_instance_id);
    }

    #[DataProvider('rebootableStates')]
    public function testInstanceRebootsTheStoredInstanceWhenItDropped(string $state)
    {
        $this->fakeHttp(
            new Response(200, [], $this->statePayload(9, $state)),
            new Response(200, [], json_encode(['data' => ['id' => 9]])),
            new Response(200, [], $this->qrPayload(9)),
        );
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => 9, 'phone_number' => '5581911110000']);

        $this->postJson(route('zapmizer.connect.instance'))
            ->assertOk()
            ->assertJsonPath('connection.id', 9)
            ->assertJsonPath('connection.qrcode', 'QR-DATA');

        // Booted again by id — no second instance, no listing.
        $boot = $this->history[1]['request'];
        $this->assertEquals('POST', $boot->getMethod());
        $this->assertEquals('http://zap.test/api/bot-instances', (string) $boot->getUri());
        $this->assertEquals(['bot_instance_id' => 9], json_decode((string) $boot->getBody(), true));
        $this->assertEquals(9, $this->team()->zapmizerConnection->bot_instance_id);
    }

    public static function rebootableStates(): array
    {
        return ['disconnected' => ['disconnected'], 'off' => ['off']];
    }

    public function testInstanceRebootStillBootingIsReportedAsBooting()
    {
        $this->fakeHttp(
            new Response(200, [], $this->statePayload(9, 'disconnected')),
            new Response(423, [], json_encode(['bot_instance_id' => 9])),
        );
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => 9]);

        $this->postJson(route('zapmizer.connect.instance'))->assertStatus(202)->assertExactJson(['code' => 'booting']);
        $this->assertEquals(9, $this->team()->zapmizerConnection->bot_instance_id);
    }

    public function testInstanceRebootOfAnUnknownIdFallsBackToTheListing()
    {
        $this->fakeHttp(
            new Response(200, [], $this->statePayload(9, 'disconnected')),
            new Response(423, [], json_encode(['message' => 'Conta do WhatsApp não encontrada.'])),
            new Response(200, [], json_encode(['data' => []])),
            new Response(201, [], json_encode(['data' => ['id' => 12]])),
            new Response(200, [], $this->qrPayload(12)),
        );
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => 9]);

        $this->postJson(route('zapmizer.connect.instance'))->assertOk()->assertJsonPath('connection.id', 12);
        $this->assertEquals(12, $this->team()->zapmizerConnection->bot_instance_id);
    }

    public function testInstanceCreateAnswersQrNotAvailableWhenTheConnectionIsRefusedRightAfter()
    {
        Log::spy();
        $this->fakeHttp(
            new Response(201, [], json_encode(['data' => ['id' => 12]])),
            // EnsureQrConnection: the team has no QR feature → 404.
            new Response(404, [], '{}'),
        );
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->postJson(route('zapmizer.connect.instance'), ['create' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'qr_not_available')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'QR'));

        // The id is kept: a retry reuses it instead of creating a third one.
        $this->assertEquals(12, $this->team()->zapmizerConnection->bot_instance_id);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'just-created instance'));
    }

    public function testInstanceRejectsAChosenOneThatDropped()
    {
        $this->fakeHttp(
            new Response(404, [], '{}'),
            new Response(200, [], json_encode(['data' => [['id' => 10, 'client' => ['cid_formatted' => '+55 81 92222-0000']]]])),
        );
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->postJson(route('zapmizer.connect.instance'), ['instance_id' => 9])
            ->assertStatus(422)
            ->assertJsonPath('code', 'instance_unavailable')
            ->assertJsonPath('instances.0.id', 10);

        $this->assertNull($this->team()->zapmizerConnection->bot_instance_id);
    }

    public function testInstanceAdoptsAChosenConnectedOne()
    {
        $this->fakeHttp(
            new Response(200, [], $this->connectedPayload(10, '5581922220000')),
            new Response(201, [], $this->webhookPayload()),
        );
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->postJson(route('zapmizer.connect.instance'), ['instance_id' => 10])
            ->assertOk()
            ->assertJsonPath('connection.number', '5581922220000');

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(10, $connection->bot_instance_id);
        $this->assertEquals('5581922220000', $connection->phone_number);
        $this->assertTrue($connection->is_active);
    }

    // --- instances / connection ----------------------------------------

    public function testInstancesListsConnectedOnesMarkingTheCurrent()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['data' => [
            ['id' => 9, 'client' => ['cid_formatted' => '+55 81 91111-0000']],
            ['id' => 10, 'client' => ['cid_formatted' => '+55 81 92222-0000']],
        ]])));
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => 10]);

        $this->getJson(route('zapmizer.connect.instances'))
            ->assertOk()
            ->assertJsonPath('instances.0.is_current', false)
            ->assertJsonPath('instances.1.is_current', true);
    }

    public function testConnectionPollingActivatesOncePaired()
    {
        $this->fakeHttp(
            new Response(200, [], $this->qrPayload(9)),
            new Response(200, [], $this->connectedPayload(9)),
            new Response(201, [], $this->webhookPayload()),
            new Response(200, [], $this->connectedPayload(9)),
        );
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => 9]);

        $this->getJson(route('zapmizer.connect.connection'))->assertOk()->assertJsonPath('connection.state', 'qrcode');
        $this->assertFalse($this->team()->zapmizerConnection->is_active);

        $this->getJson(route('zapmizer.connect.connection'))->assertOk()->assertJsonPath('connection.state', 'connected');
        $connection = $this->team()->zapmizerConnection;
        $this->assertTrue($connection->is_active);
        $this->assertEquals('whsec_new', $connection->webhook_secret);
        $connectedAt = $connection->connected_at;

        // Already paired and registered: the poll writes nothing more.
        $this->getJson(route('zapmizer.connect.connection'))->assertOk();
        $this->assertEquals($connectedAt, $this->team()->zapmizerConnection->connected_at);
        $this->assertCount(4, $this->history);
    }

    public function testConnectionSurvivesAWebhookRegistrationFailure()
    {
        Log::spy();
        $this->fakeHttp(
            new Response(200, [], $this->connectedPayload(9)),
            new Response(500, [], ''),
        );
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => 9]);

        $this->getJson(route('zapmizer.connect.connection'))->assertOk()->assertJsonPath('connection.state', 'connected');

        $connection = $this->team()->zapmizerConnection;
        $this->assertTrue($connection->is_active);
        $this->assertNull($connection->webhook_secret);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'could not register the webhook'));
    }

    #[DataProvider('connectionFailures')]
    public function testConnectionMapsZapmizerErrors(Response $response, int $status, array $json)
    {
        $this->fakeHttp($response);
        $this->actingAsUser();
        $this->authorizedTeam(['bot_instance_id' => 9]);

        $this->getJson(route('zapmizer.connect.connection'))->assertStatus($status)->assertJson($json);
    }

    public static function connectionFailures(): array
    {
        return [
            'instance gone' => [new Response(404, [], '{}'), 409, ['code' => 'no_instance']],
            'token revoked' => [new Response(401, [], '{}'), 200, ['code' => 'reauth_required']],
            'zapmizer down' => [new Response(503, [], ''), 503, ['code' => 'zapmizer_unavailable']],
        ];
    }

    // --- destroy --------------------------------------------------------

    public function testDestroyDeletesTheConnectionAndTheRemoteWebhook()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['message' => 'Webhook removido.'])));
        $this->actingAsUser();
        $this->authorizedTeam(['webhook_id' => 42, 'webhook_secret' => 'whsec']);

        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk()->assertExactJson(['status' => 'disconnected']);

        $this->assertEquals(0, ZapmizerConnection::count());
        $this->assertCount(1, $this->history);
        $this->assertEquals('DELETE', $this->history[0]['request']->getMethod());
        $this->assertEquals('http://zap.test/api/webhooks/42', (string) $this->history[0]['request']->getUri());
        $this->assertEquals('Bearer team-token', $this->history[0]['request']->getHeaderLine('Authorization'));

        // Nothing to delete: no call, still ok.
        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk();
        $this->assertCount(1, $this->history);
    }

    public function testDestroyWithoutARegisteredWebhookCallsNothing()
    {
        $this->fakeHttp();
        $this->actingAsUser();
        $this->authorizedTeam();

        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk();

        $this->assertEquals(0, ZapmizerConnection::count());
        $this->assertCount(0, $this->history);
    }

    public function testDestroyIsBestEffortOnTheRemoteSide()
    {
        Log::spy();
        $this->fakeHttp(new Response(500, [], ''));
        $this->actingAsUser();
        $this->authorizedTeam(['webhook_id' => 42, 'webhook_secret' => 'whsec']);

        $this->deleteJson(route('zapmizer.connect.destroy'))->assertOk();

        $this->assertEquals(0, ZapmizerConnection::count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'could not delete the webhook'));
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
        $this->fakeHttp(new Response(200, [], json_encode(['token' => '1|sanctum', 'team_id' => 7, 'team_name' => 'Acme'])));
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
