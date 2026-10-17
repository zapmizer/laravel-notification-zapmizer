<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use NotificationChannels\Zapmizer\Events\ReconnectRefused;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;
use NotificationChannels\Zapmizer\Http\Controllers\ReconnectController;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\DrivesTheConnectRoutes;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Concerns\InvalidBaseUris;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesFirstTeam;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesNothing;
use NotificationChannels\Zapmizer\Test\Fixtures\Team;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ReconnectRouteTest extends TestCase
{
    use AssertsContract;
    use CreatesConnectionTables;
    use DrivesTheConnectRoutes;
    use FakesZapmizerHttp;
    use InvalidBaseUris;

    private const RECONNECT_URL = 'http://zap.test/api/bot-instances/9/reconnect';

    private const NEEDS_RECONNECT_MESSAGE = 'O número precisa ser reconectado pelo cliente.';

    private const CLIENT_URL = 'https://app.zapmizer.com/connect/reconnect/9?signature=abc';

    /** @var array<int, array{request: \GuzzleHttp\Psr7\Request}> */
    protected array $history = [];

    /** @var array<int, \Throwable> */
    protected array $reported = [];

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.base_uri', 'http://zap.test/api/');
        $app['config']->set('zapmizer.partner.id', 'partner-id');
        $app['config']->set('zapmizer.partner.secret', 'partner-secret');
        $app['config']->set('zapmizer.connect.resolver', ResolvesFirstTeam::class);
    }

    protected function reconnect(): TestResponse
    {
        return $this->postJson(route('zapmizer.connect.reconnect'));
    }

    protected function reconnectCallback(array $query): TestResponse
    {
        return $this->get(route('zapmizer.connect.reconnect.callback', $query));
    }

    protected function reconnectPending(array $overrides = []): array
    {
        return [ReconnectController::SESSION_KEY => $overrides + [
            'state' => 's',
            'connectable' => Team::class . ':' . $this->team()->id,
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]];
    }

    protected function connectPending(): array
    {
        return [ConnectController::SESSION_KEY => [
            'state' => 's', 'connectable' => Team::class . ':' . $this->team()->id, 'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]];
    }

    /**
     * A real connect first, then `$answer` for the reconnect.
     */
    protected function connectedThenReconnect(Response|\Throwable ...$answers): void
    {
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer(), ...$answers);
        $this->actingAsUser();
        $this->connect()->assertSee('status: "ok"', false);
    }

    protected static function answer(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $body);
    }

    protected static function needsReconnect(?string $expiresAt = '2026-10-07T15:00:00Z', string $url = self::CLIENT_URL): string
    {
        return json_encode([
            'error' => 'needs_reconnect',
            'message' => self::NEEDS_RECONNECT_MESSAGE,
            'url' => $url,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function reconnectCalls(): int
    {
        return count(array_filter($this->history, fn (array $entry) => (string) $entry['request']->getUri() === self::RECONNECT_URL));
    }

    // --- routes ---------------------------------------------------------

    public function testRoutesAreRegisteredBehindAuthWithAThrottle()
    {
        $start = Route::getRoutes()->getByName('zapmizer.connect.reconnect');
        $callback = Route::getRoutes()->getByName('zapmizer.connect.reconnect.callback');

        $this->assertNotNull($start);
        $this->assertNotNull($callback);
        $this->assertContains('auth', $start->gatherMiddleware());
        $this->assertContains('auth', $callback->gatherMiddleware());
        $this->assertContains('throttle:10,1', $start->gatherMiddleware());
        $this->assertSame(['POST'], $start->methods());
        $this->assertContains('GET', $callback->methods());
        $this->assertSame('zapmizer/connect/reconnect', $start->uri());
        $this->assertSame('zapmizer/connect/reconnect/callback', $callback->uri());
    }

    // --- start ----------------------------------------------------------

    /** @dataProvider nothingToReconnect */
    #[DataProvider('nothingToReconnect')]
    public function testK1NothingToReconnect(Closure $state)
    {
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer());
        $this->actingAsUser();
        $state($this);

        $this->reconnect()->assertStatus(409)->assertExactJson(['code' => 'no_instance']);

        $this->assertSame(0, $this->reconnectCalls());
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    public static function nothingToReconnect(): array
    {
        return [
            'no connection' => [fn (self $test) => null],
            'no token' => [function (self $test) {
                $test->connect();
                $test->team()->zapmizerConnection->forceFill(['api_token' => null])->save();
            }],
            'no instance' => [function (self $test) {
                $test->connect();
                $test->team()->zapmizerConnection->forceFill(['bot_instance_id' => null])->save();
            }],
        ];
    }

    /** @dataProvider onlineOrStarting */
    #[DataProvider('onlineOrStarting')]
    public function testK2OnlineOrStarting(int $status, string $body, string $answer)
    {
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', $status, $body);
        $this->connectedThenReconnect(self::answer($status, $body));

        $this->reconnect()->assertOk()->assertExactJson(['status' => $answer]);

        /** @var PsrRequest $request */
        $request = end($this->history)['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::RECONNECT_URL, (string) $request->getUri());
        $this->assertSame('Bearer 1|sanctum', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['redirect_uri', 'state', 'expires_in'], array_keys($body));
        $this->assertSame('http://localhost/zapmizer/connect/reconnect/callback', $body['redirect_uri']);
        $this->assertSame(40, strlen($body['state']));
        $this->assertSame(1800, $body['expires_in']);
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    public static function onlineOrStarting(): array
    {
        return [
            'online' => [200, '{"status":"online"}', 'online'],
            'starting' => [202, '{"status":"starting","links":{"connection":"https://app.zapmizer.com/api/bot-instances/9/connection"}}', 'starting'],
        ];
    }

    /** @dataProvider clientExpiries */
    #[DataProvider('clientExpiries')]
    public function testK3NeedsClient(?string $expiresAt, ?string $answered)
    {
        $body = self::needsReconnect($expiresAt);
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 409, $body);
        $this->connectedThenReconnect(self::answer(409, $body));

        $this->reconnect()->assertOk()->assertExactJson([
            'status' => 'needs_client',
            'url' => self::CLIENT_URL,
            'expires_at' => $answered,
        ]);

        $sent = json_decode((string) end($this->history)['request']->getBody(), true);
        $this->assertSame($sent['state'], session(ReconnectController::SESSION_KEY)['state']);
    }

    public static function clientExpiries(): array
    {
        return [
            'with expiry' => ['2026-10-07T15:00:00Z', '2026-10-07T15:00:00+00:00'],
            'without expiry' => [null, null],
        ];
    }

    /** @dataProvider refusals */
    #[DataProvider('refusals')]
    public function testK4ARefusalIsAnsweredAndAnnounced(string $code, ?string $message, string $answered)
    {
        $body = json_encode(array_filter(['error' => $code, 'message' => $message]), JSON_UNESCAPED_UNICODE);
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 402, $body);
        Event::fake([ReconnectRefused::class]);
        $this->connectedThenReconnect(self::answer(402, $body));

        $this->reconnect()->assertOk()->assertExactJson(['status' => 'refused', 'code' => $code, 'message' => $answered]);

        Event::assertDispatchedTimes(ReconnectRefused::class, 1);
        Event::assertDispatched(ReconnectRefused::class, fn (ReconnectRefused $event) => $event->code === $code
            && $event->message === $message
            && $event->connectable->is($this->team())
            && $event->connection->is($this->team()->zapmizerConnection));
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    public static function refusals(): array
    {
        return [
            'plan_limit with message' => ['plan_limit', 'Plano cheio.', 'Plano cheio.'],
            'plan_limit without message' => ['plan_limit', null, 'The Zapmizer plan has no room for another number online.'],
            'subscription_required with message' => ['subscription_required', 'Plano cheio.', 'Plano cheio.'],
            'subscription_required without message' => ['subscription_required', null, 'The Zapmizer account has no active subscription.'],
        ];
    }

    public function testK4bAFailingListenerKeepsTheRefusal()
    {
        $this->recordReports();
        Event::listen(ReconnectRefused::class, fn () => throw new \RuntimeException('listener bug'));
        $this->connectedThenReconnect(self::answer(402, '{"error":"plan_limit","message":"Plano cheio."}'));

        $this->reconnect()->assertOk()->assertExactJson(['status' => 'refused', 'code' => 'plan_limit', 'message' => 'Plano cheio.']);

        $this->assertCount(1, $this->reported);
        $this->assertSame('listener bug', $this->reported[0]->getMessage());
    }

    public function testK4cAnUnknownRefusalCodeGetsTheGenericTextOutsideTheContract()
    {
        Event::fake([ReconnectRefused::class]);
        $this->connectedThenReconnect(self::answer(402, '{"error":"foo"}'));

        $this->reconnect()->assertOk()->assertExactJson([
            'status' => 'refused',
            'code' => 'foo',
            'message' => 'Zapmizer refused to reconnect this number.',
        ]);

        Event::assertDispatched(ReconnectRefused::class, fn (ReconnectRefused $event) => $event->code === 'foo' && $event->message === null);
    }

    /** @dataProvider refusalsWithoutACodeOutsideTheContract */
    #[DataProvider('refusalsWithoutACodeOutsideTheContract')]
    public function testK5A402WithoutACodeIsUnavailable(string $body)
    {
        Event::fake([ReconnectRefused::class]);
        $this->recordReports();
        $this->connectedThenReconnect(self::answer(402, $body));

        $this->reconnect()->assertStatus(503)->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertCount(1, $this->reported);
        Event::assertNotDispatched(ReconnectRefused::class);
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    public static function refusalsWithoutACodeOutsideTheContract(): array
    {
        return [
            'only a message' => ['{"message":"Payment required."}'],
            'a numeric error' => ['{"error":402}'],
        ];
    }

    /** @dataProvider unauthorizedOrForbidden */
    #[DataProvider('unauthorizedOrForbidden')]
    public function testK6AnUnauthorizedOrForbiddenTokenIsReauthRequired(int $status, string $body, bool $fromTheContract, ?string $error)
    {
        if ($fromTheContract) {
            $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', $status, $body);
        }

        Log::spy();
        $this->recordReports();
        $this->connectedThenReconnect(self::answer($status, $body));

        $this->reconnect()->assertStatus(409)->assertExactJson(['code' => 'reauth_required']);

        $this->assertCount(0, $this->reported);
        $this->assertNull(session(ReconnectController::SESSION_KEY));

        if ($status === 403) {
            Log::shouldHaveReceived('warning')->with('zapmizer: reconnect refused.', [
                'connection_id' => $this->team()->zapmizerConnection->id,
                'status' => 403,
                'error' => $error,
            ])->once();
        }
    }

    public static function unauthorizedOrForbidden(): array
    {
        return [
            'HTTP 401' => [401, '{"message":"Unauthenticated."}', true, null],
            '403 without error' => [403, '{"message":"Só uma conexão de parceiro religa um número."}', true, null],
            '403 missing_ability OutsideTheContract' => [403, '{"error":"missing_ability","message":"Missing ability."}', false, 'missing_ability'],
        ];
    }

    public function testK7AGoneInstanceIsNoInstance()
    {
        $body = '{"message":"No query results for model."}';
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 404, $body);
        $this->connectedThenReconnect(self::answer(404, $body));

        $this->reconnect()->assertStatus(409)->assertExactJson(['code' => 'no_instance']);

        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    /** @dataProvider everythingElseFromTheContract */
    #[DataProvider('everythingElseFromTheContract')]
    public function testK8EverythingElseFromTheContractIsUnavailable(int $status, string $body, array $headers)
    {
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', $status, $body, $headers);

        $this->assertUnavailable(self::answer($status, $body, $headers));
    }

    public static function everythingElseFromTheContract(): array
    {
        return [
            'HTTP 429' => [429, '{"message":"Too Many Attempts."}', ['Retry-After' => '42']],
            '422 redirect_uri' => [422, '{"message":"The redirect uri is not allowed.","errors":{"redirect_uri":["The redirect uri is not allowed."]}}', []],
        ];
    }

    /** @dataProvider everythingElseOutsideTheContract */
    #[DataProvider('everythingElseOutsideTheContract')]
    public function testK8EverythingElseOutsideTheContractIsUnavailable(Closure $answer)
    {
        $this->assertUnavailable($answer());
    }

    public static function everythingElseOutsideTheContract(): array
    {
        return [
            '409 bot_offline' => [fn () => self::answer(409, '{"error":"bot_offline","message":"Offline."}')],
            'HTTP 500' => [fn () => self::answer(500, '{"message":"Server Error"}')],
            'network' => [fn () => new ConnectException('Connection refused', new PsrRequest('POST', self::RECONNECT_URL))],
            'HTTP 302' => [fn () => new Response(302, ['Location' => 'http://zap.test/login'])],
        ];
    }

    protected function assertUnavailable(Response|\Throwable $answer): void
    {
        $this->recordReports();
        $this->connectedThenReconnect($answer);

        $this->reconnect()->assertStatus(503)->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertCount(1, $this->reported);
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    public function testK9NothingToConnect()
    {
        config()->set('zapmizer.connect.resolver', ResolvesNothing::class);
        $this->fakeHttp();
        $this->actingAsUser();

        $this->reconnect()->assertForbidden()->assertExactJson(['code' => 'no_connectable']);

        $this->assertCount(0, $this->history);
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    public function testK10AReconnectLeavesAPendingConnectAlone()
    {
        $this->connectedThenReconnect(self::answer(200, '{"status":"online"}'));
        $pending = ['state' => 'connect-state', 'connectable' => Team::class . ':' . $this->team()->id, 'expires_at' => now()->addMinutes(5)->toIso8601String()];

        $this->withSession([ConnectController::SESSION_KEY => $pending])->reconnect()->assertOk();

        $this->assertSame($pending, session(ConnectController::SESSION_KEY));
    }

    public function testK11TheEleventhReconnectInAMinuteIsThrottled()
    {
        $this->fakeHttp();
        $this->actingAsUser();

        for ($i = 0; $i < 10; $i++) {
            $this->reconnect()->assertStatus(409);
        }

        $this->reconnect()->assertStatus(429);
        $this->assertCount(0, $this->history);
    }

    public function testK12AnUnsafeUrlIsUnavailableOutsideTheContract()
    {
        $this->assertUnavailable(self::answer(409, self::needsReconnect(null, 'javascript:alert(1)')));
    }

    public function testK13AnUnreadableTokenIsReauthRequired()
    {
        Log::spy();
        $this->connectedThenReconnect();
        $this->swapAppKey();

        $this->reconnect()->assertStatus(409)->assertExactJson(['code' => 'reauth_required']);

        Log::shouldHaveReceived('warning')->with('zapmizer: could not read the token to reconnect.', ['connection_id' => $this->team()->zapmizerConnection->id])->once();
        $this->assertCount(2, $this->history);
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    public function testK14AnInvalidBaseIsUnavailableAndSendsNothing()
    {
        $this->recordReports();
        $this->connectedThenReconnect();
        config()->set('zapmizer.base_uri', 'localhost/api/');

        $this->reconnect()->assertStatus(503)->assertExactJson(['code' => 'zapmizer_unavailable']);

        $this->assertCount(1, $this->reported);
        $this->assertIsTheInvalidBaseUri($this->reported[0]);
        $this->assertCount(2, $this->history);
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    public function testTwoReconnectsInARowOnlyTheLastCounts()
    {
        $this->connectedThenReconnect(self::answer(409, self::needsReconnect()), self::answer(409, self::needsReconnect()));

        $this->reconnect()->assertOk();
        $first = json_decode((string) $this->history[2]['request']->getBody(), true)['state'];
        $this->reconnect()->assertOk();
        $second = json_decode((string) $this->history[3]['request']->getBody(), true)['state'];
        $this->assertNotSame($first, $second);

        $this->reconnectCallback(['status' => 'reconnected', 'state' => $first])->assertSee('status: "invalid_state"', false);
        $this->assertSame($second, session(ReconnectController::SESSION_KEY)['state']);

        $this->reconnectCallback(['status' => 'reconnected', 'state' => $second])->assertSee('status: "reconnected"', false);
        $this->assertNull(session(ReconnectController::SESSION_KEY));
    }

    // --- callback -------------------------------------------------------

    public function testQ1Reconnected()
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->reconnectPending())
            ->get(route('zapmizer.connect.reconnect.callback', ['status' => 'reconnected', 'state' => 's']))
            ->assertOk()
            ->assertSee("source: 'zapmizer-reconnect'", false)
            ->assertSee('status: "reconnected"', false)
            ->assertSee('WhatsApp number reconnected.')
            ->assertSee('window.opener.postMessage', false);

        $this->assertCount(0, $this->history);
        $this->assertNull(session(ReconnectController::SESSION_KEY));
        $this->assertNull($this->team()->zapmizerConnection);
    }

    /** @dataProvider hostedPageRefusals */
    #[DataProvider('hostedPageRefusals')]
    public function testQ2TheHostedPageRefused(string $error, string $status, string $message)
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->reconnectPending())
            ->get(route('zapmizer.connect.reconnect.callback', ['error' => $error, 'state' => 's']))
            ->assertOk()
            ->assertSee("source: 'zapmizer-reconnect'", false)
            ->assertSee('status: "' . $status . '"', false)
            ->assertSee($message);

        $this->assertCount(0, $this->history);
    }

    public static function hostedPageRefusals(): array
    {
        return [
            'access_denied' => ['access_denied', 'denied', 'Reconnection cancelled.'],
            'plan_limit' => ['plan_limit', 'plan_limit', 'The Zapmizer plan has no room for another WhatsApp number.'],
            'subscription_required' => ['subscription_required', 'subscription_required', 'Your Zapmizer account has no active subscription. Subscribe on Zapmizer and try again.'],
        ];
    }

    /** @dataProvider otherReturns */
    #[DataProvider('otherReturns')]
    public function testQ3AnythingElseFails(array $query, string $status, ?string $warned)
    {
        Log::spy();
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->reconnectPending())
            ->get(route('zapmizer.connect.reconnect.callback', $query + ['state' => 's']))
            ->assertOk()
            ->assertSee('status: "' . $status . '"', false);

        if ($warned !== null) {
            Log::shouldHaveReceived('warning')->with('zapmizer: unknown reconnect error.', ['error' => $warned])->once();
        } else {
            Log::shouldNotHaveReceived('warning');
        }
    }

    public static function otherReturns(): array
    {
        return [
            'unknown error' => [['error' => 'xyz'], 'failed', 'xyz'],
            'neither' => [[], 'failed', null],
            'another status' => [['status' => 'other'], 'failed', null],
            'error wins over status' => [['error' => 'plan_limit', 'status' => 'reconnected'], 'plan_limit', null],
        ];
    }

    public function testQ3TheFailedPageSaysSo()
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->reconnectPending())
            ->get(route('zapmizer.connect.reconnect.callback', ['state' => 's']))
            ->assertSee('The number could not be reconnected. Close this window and try again.');
    }

    /** @dataProvider badStates */
    #[DataProvider('badStates')]
    public function testQ4ABadStateIsInvalid(Closure $session, array $query)
    {
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($session($this))
            ->get(route('zapmizer.connect.reconnect.callback', $query + ['status' => 'reconnected']))
            ->assertOk()
            ->assertSee("source: 'zapmizer-reconnect'", false)
            ->assertSee('status: "invalid_state"', false);
    }

    public static function badStates(): array
    {
        return [
            'no pending' => [fn (self $test) => [], ['state' => 's']],
            'no state in the query' => [fn (self $test) => $test->reconnectPending(), []],
            'expired' => [fn (self $test) => $test->reconnectPending(['expires_at' => now()->subSecond()->toIso8601String()]), ['state' => 's']],
            'another connectable' => [fn (self $test) => $test->reconnectPending(['connectable' => Team::class . ':999']), ['state' => 's']],
            'the state of a connect' => [fn (self $test) => $test->connectPending(), ['state' => 's']],
        ];
    }

    public function testQ4AReusedStateIsInvalid()
    {
        $this->fakeHttp();
        $this->actingAsUser();
        $this->withSession($this->reconnectPending());

        $this->reconnectCallback(['status' => 'reconnected', 'state' => 's'])->assertSee('status: "reconnected"', false);
        $this->reconnectCallback(['status' => 'reconnected', 'state' => 's'])->assertSee('status: "invalid_state"', false);
    }

    /** @dataProvider arrayQueries */
    #[DataProvider('arrayQueries')]
    public function testQ5ArraysNeverAnswer500(array $query, string $status, bool $warned)
    {
        Log::spy();
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->reconnectPending())
            ->get(route('zapmizer.connect.reconnect.callback', $query))
            ->assertOk()
            ->assertSee('status: "' . $status . '"', false);

        if ($warned) {
            Log::shouldHaveReceived('warning')->with('zapmizer: unknown reconnect error.', ['error' => '(array)'])->once();
        } else {
            Log::shouldNotHaveReceived('warning');
        }
    }

    public static function arrayQueries(): array
    {
        return [
            'array error with reconnected' => [['status' => 'reconnected', 'error' => ['x'], 'state' => 's'], 'failed', true],
            'array state' => [['status' => 'reconnected', 'state' => ['x']], 'invalid_state', false],
            'array status' => [['status' => ['reconnected'], 'state' => 's'], 'failed', false],
        ];
    }

    public function testQ5WithoutAConnectable()
    {
        config()->set('zapmizer.connect.resolver', ResolvesNothing::class);
        $this->fakeHttp();
        $this->actingAsUser();

        $this->withSession($this->reconnectPending())
            ->get(route('zapmizer.connect.reconnect.callback', ['status' => 'reconnected', 'state' => 's']))
            ->assertOk()
            ->assertSee("source: 'zapmizer-reconnect'", false)
            ->assertSee('status: "no_connectable"', false);
    }

    public function testP1APublishedConnectResultDoesNotReachTheReconnect()
    {
        $dir = sys_get_temp_dir() . '/zapmizer-p1-' . uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir . '/connect-result.blade.php', 'PUBLISHED {{ $status }}');
        View::prependNamespace('zapmizer', $dir);

        try {
            $this->fakeHttp();
            $this->actingAsUser();

            $this->withSession($this->connectPending())
                ->get(route('zapmizer.connect.callback', ['error' => 'access_denied', 'state' => 's']))
                ->assertSee('PUBLISHED denied');

            $this->withSession($this->reconnectPending())
                ->get(route('zapmizer.connect.reconnect.callback', ['status' => 'reconnected', 'state' => 's']))
                ->assertSee("source: 'zapmizer-reconnect'", false)
                ->assertSee('status: "reconnected"', false)
                ->assertDontSee('PUBLISHED');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
