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

    /**
     * What POST /api/connect/token answers once the hosted page paired the
     * number: token, team, number, instance and the webhook registered for
     * the session's webhook_url — secret only when it was created now.
     */
    protected function tokenPayload(array $overrides = []): string
    {
        return json_encode(array_merge([
            'token' => '1|sanctum', 'team_id' => 7, 'team_name' => 'Acme',
            'phone_number' => '5581911110000', 'bot_instance_id' => 9,
            'webhook_id' => 42, 'webhook_secret' => 'whsec_new',
        ], $overrides));
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
        $response->assertJsonMissingPath('connection.api_token');
        // Without `live` nothing reaches Zapmizer and nothing live is claimed.
        $response->assertJsonMissingPath('connection.state');
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
        $response->assertJsonMissingPath('connection.api_token');
        $response->assertJsonMissingPath('connection.webhook_secret');

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
        // Zapmizer registers the receiver during the pairing: the route goes
        // along with the session.
        $this->assertEquals('http://localhost/zapmizer/webhook', $body['webhook_url']);
        // The TTL is left to Zapmizer (never below its 900s floor).
        $this->assertArrayNotHasKey('expires_in', $body);
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

    public function testCallbackExchangesTheCodeAndStoresAnActiveConnection()
    {
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
        $this->assertNotNull($connection->connected_at);
        $this->assertEquals(42, $connection->webhook_id);
        $this->assertEquals('whsec_new', $connection->webhook_secret);
        $this->assertNull($connection->webhook_previous_secret);
        $this->assertTrue($connection->is_active);
        $this->assertTrue($this->team()->fresh()->hasActiveZapmizer());

        // The state is single-use; one call — no instance, no webhook registration.
        $this->assertNull(session(ConnectController::SESSION_KEY));
        $this->assertCount(1, $this->history);
        $this->assertEquals(['code' => '12.secret'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testCallbackWithoutAPairedNumberStaysInactive()
    {
        // A Zapmizer without the hosted pairing (or a session without one)
        // answers only the token: nothing to send from, nothing to activate.
        $this->fakeHttp(new Response(200, [], json_encode(['token' => '1|sanctum', 'team_id' => 7, 'team_name' => 'Acme'])));
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals('1|sanctum', $connection->api_token);
        $this->assertNull($connection->phone_number);
        $this->assertNull($connection->connected_at);
        $this->assertNull($connection->webhook_id);
        $this->assertFalse($connection->is_active);
    }

    public function testCallbackRotatesWhenZapmizerReusedTheWebhookAndKeptTheSecret()
    {
        $this->fakeHttp(
            new Response(200, [], $this->tokenPayload(['webhook_secret' => null])),
            new Response(200, [], json_encode(['data' => ['id' => 42, 'secret' => 'whsec_rotated', 'previous_secret' => 'whsec_unknown']])),
        );
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(42, $connection->webhook_id);
        $this->assertEquals('whsec_rotated', $connection->webhook_secret);
        $this->assertEquals('whsec_unknown', $connection->webhook_previous_secret);
        $this->assertTrue($connection->is_active);

        // Rotated with the NEW token, on the webhook Zapmizer named.
        $rotate = $this->history[1]['request'];
        $this->assertEquals('POST', $rotate->getMethod());
        $this->assertEquals('http://zap.test/api/webhooks/42/secret', (string) $rotate->getUri());
        $this->assertEquals('Bearer 1|sanctum', $rotate->getHeaderLine('Authorization'));
    }

    #[DataProvider('rotationFailures')]
    public function testCallbackDeactivatesWhenTheSecretCannotBeObtained(Response $failure)
    {
        Log::spy();
        $this->fakeHttp(new Response(200, [], $this->tokenPayload(['webhook_secret' => null])), $failure);
        $this->actingAsUser();

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "webhook_failed"', false)
            ->assertSee('window.opener.postMessage', false);

        // The pairing is kept — the token, number and webhook id are real —
        // but nothing can be verified without a secret: inactive, with a log.
        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals('1|sanctum', $connection->api_token);
        $this->assertEquals('5581911110000', $connection->phone_number);
        $this->assertEquals(42, $connection->webhook_id);
        $this->assertNull($connection->webhook_secret);
        $this->assertFalse($connection->is_active);
        $this->assertFalse($this->team()->fresh()->hasActiveZapmizer());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'could not obtain the webhook secret'));
    }

    public static function rotationFailures(): array
    {
        return [
            'zapmizer down' => [new Response(500, [], '')],
            'token refused' => [new Response(401, [], '{}')],
            'webhook gone' => [new Response(404, [], '{}')],
        ];
    }

    public function testCallbackKeepsTheStoredSecretWhenTheSameWebhookIsReused()
    {
        // Reconnecting the same team: Zapmizer finds our URL already
        // registered and answers the same id without a secret. The one
        // stored is still the one signing — no rotation needed.
        $this->fakeHttp(new Response(200, [], $this->tokenPayload(['token' => '2|sanctum', 'phone_number' => '5581922220000', 'bot_instance_id' => 10, 'webhook_secret' => null])));
        $this->actingAsUser();
        $this->authorizedTeam([
            'phone_number' => '5581911110000', 'bot_instance_id' => 9, 'connected_at' => now()->subDay(), 'is_active' => true,
            'webhook_id' => 42, 'webhook_secret' => 'whsec_old', 'webhook_previous_secret' => 'whsec_older',
        ]);

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(1, ZapmizerConnection::count());
        $this->assertEquals('2|sanctum', $connection->api_token);
        $this->assertEquals('5581922220000', $connection->phone_number);
        $this->assertEquals(10, $connection->bot_instance_id);
        $this->assertTrue($connection->connected_at->isAfter(now()->subMinute()));
        $this->assertEquals(42, $connection->webhook_id);
        $this->assertEquals('whsec_old', $connection->webhook_secret);
        $this->assertEquals('whsec_older', $connection->webhook_previous_secret);
        $this->assertTrue($connection->is_active);
        $this->assertCount(1, $this->history);
    }

    public function testCallbackOnTheSameTeamWithANewWebhookDeletesTheOldOne()
    {
        $this->fakeHttp(
            new Response(200, [], $this->tokenPayload(['token' => '2|sanctum', 'webhook_id' => 43, 'webhook_secret' => 'whsec_43'])),
            new Response(200, [], json_encode(['message' => 'Webhook removido.'])),
        );
        $this->actingAsUser();
        $this->authorizedTeam(['phone_number' => '5581911110000', 'bot_instance_id' => 9, 'is_active' => true, 'webhook_id' => 42, 'webhook_secret' => 'whsec_old']);

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertEquals(43, $connection->webhook_id);
        $this->assertEquals('whsec_43', $connection->webhook_secret);

        // The old webhook is deleted with the OLD token, before it is replaced.
        $delete = $this->history[1]['request'];
        $this->assertEquals('DELETE', $delete->getMethod());
        $this->assertEquals('http://zap.test/api/webhooks/42', (string) $delete->getUri());
        $this->assertEquals('Bearer team-token', $delete->getHeaderLine('Authorization'));
    }

    public function testCallbackOnAnotherTeamForgetsThePairingAndDeletesTheOldWebhook()
    {
        Log::spy();
        $this->fakeHttp(
            new Response(200, [], $this->tokenPayload(['token' => '2|sanctum', 'team_id' => 8, 'team_name' => 'Other', 'phone_number' => '5581933330000', 'bot_instance_id' => 30, 'webhook_id' => 77, 'webhook_secret' => 'whsec_77'])),
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
        // Nothing of the old team survives: number, instance and webhook are
        // the new team's, and the old previous secret is not carried over.
        $this->assertEquals('5581933330000', $connection->phone_number);
        $this->assertEquals(30, $connection->bot_instance_id);
        $this->assertNotNull($connection->connected_at);
        $this->assertEquals(77, $connection->webhook_id);
        $this->assertEquals('whsec_77', $connection->webhook_secret);
        $this->assertNull($connection->webhook_previous_secret);
        $this->assertTrue($connection->is_active);

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
            new Response(200, [], $this->tokenPayload(['token' => '2|sanctum', 'team_id' => 8, 'team_name' => 'Other', 'webhook_id' => 77, 'webhook_secret' => 'whsec_77'])),
            new Response(401, [], '{}'),
        );
        $this->actingAsUser();
        $this->authorizedTeam(['phone_number' => '5581911110000', 'bot_instance_id' => 9, 'is_active' => true, 'webhook_id' => 42, 'webhook_secret' => 'whsec_old']);

        $this->withSession($this->pendingSession())
            ->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertOk()
            ->assertSee('status: "ok"', false);

        $this->assertEquals(8, $this->team()->zapmizerConnection->zapmizer_team_id);
        $this->assertEquals(77, $this->team()->zapmizerConnection->webhook_id);
        $this->assertEquals('whsec_77', $this->team()->zapmizerConnection->webhook_secret);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'could not delete the webhook'));
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
            // The hosted page could not pair: the plan is full, or the team
            // cannot pair by QR code. Each one is its own status for the screen.
            'plan limit' => [['state' => 's', 'connectable' => $valid, 'expires_at' => $future], ['error' => 'plan_limit', 'state' => 's'], 'plan_limit'],
            'qr unavailable' => [['state' => 's', 'connectable' => $valid, 'expires_at' => $future], ['error' => 'qr_unavailable', 'state' => 's'], 'qr_unavailable'],
            'unknown error' => [['state' => 's', 'connectable' => $valid, 'expires_at' => $future], ['error' => 'something_new', 'state' => 's'], 'exchange_failed'],
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
