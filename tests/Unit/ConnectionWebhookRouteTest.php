<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyConnectionWebhookSignature;
use NotificationChannels\Zapmizer\Support\ConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\DrivesTheConnectRoutes;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesFirstTeam;
use NotificationChannels\Zapmizer\Test\Fixtures\Team;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConnectionWebhookRouteTest extends TestCase
{
    use AssertsContract;
    use CreatesConnectionTables;
    use DrivesTheConnectRoutes;
    use FakesZapmizerHttp;
    use SignsWebhookDeliveries;
    use TogglesConnectionWebhook;

    private const KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

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

    /**
     * @return array<string, array{0: ?string, 1: bool}> `null` takes the key out
     */
    public static function perConnectionValues(): array
    {
        return [
            'true' => ['true', true],
            'one' => ['1', true],
            'false' => ['false', false],
            'abc' => ['abc', false],
            'absent' => [null, false],
        ];
    }

    private function applyPerConnection(?string $value): void
    {
        if ($value === null) {
            config()->set('zapmizer.webhook', Arr::except(config('zapmizer.webhook'), 'per_connection'));

            return;
        }

        config()->set('zapmizer.webhook.per_connection', $value);
    }

    /** @dataProvider perConnectionValues */
    #[DataProvider('perConnectionValues')]
    public function testC46TheRouteFollowsTheReader(?string $value, bool $on)
    {
        $this->applyPerConnection($value);
        $this->reloadPackageRoutes();

        $this->assertSame($on, ConnectionWebhook::enabled());
        $this->assertSame($on, Route::has('zapmizer.webhook.connection'));
    }

    /** @dataProvider perConnectionValues */
    #[DataProvider('perConnectionValues')]
    public function testC46TheStartFollowsTheReader(?string $value, bool $on)
    {
        $this->applyPerConnection($value);
        $this->reloadPackageRoutes();
        $this->recordReports();
        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated());

        $response = $this->startConnect();

        if ($on) {
            $response->assertStatus(500);
            $this->assertCount(1, $this->reported);
            $this->assertInstanceOf(LogicException::class, $this->reported[0]);
            $this->assertCount(0, $this->history);
        } else {
            $response->assertOk();
            $this->assertSame('http://localhost/zapmizer/webhook', $this->sentBody(0)['webhook_url']);
        }
    }

    public function testC2WithTheOptionOffTheRouteDoesNotExist()
    {
        config()->set('zapmizer.webhook.per_connection', false);
        $this->reloadPackageRoutes();

        $this->assertFalse(Route::has('zapmizer.webhook.connection'));

        $this->deliverSigned($this->messageEnvelope(), 'x', path: '/zapmizer/webhook/' . self::KEY)->assertNotFound();
    }

    public function testC38TurningTheOptionOffLosesTheKeyedDeliveries()
    {
        $this->addWebhookKeyColumn();
        $this->connectedTeam('whsec_a', '5581911110000', ['webhook_key' => self::KEY]);
        $envelope = $this->messageEnvelope();

        $this->enablePerConnection();
        $this->deliverSigned($envelope, 'whsec_a', path: '/zapmizer/webhook/' . self::KEY)->assertOk();

        config()->set('zapmizer.webhook.per_connection', false);
        $this->reloadPackageRoutes();
        $this->watchQueries();

        $this->deliverSigned($envelope, 'whsec_a', path: '/zapmizer/webhook/' . self::KEY)->assertNotFound();
        $this->assertSame([], $this->connectionQueries());
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function missingSchema(): array
    {
        return [
            'no column' => [false],
            'no table' => [true],
        ];
    }

    /** @dataProvider missingSchema */
    #[DataProvider('missingSchema')]
    public function testC6WithoutTheColumnADeliveryIs500(bool $dropTable)
    {
        if ($dropTable) {
            Schema::drop('zapmizer_connections');
        }

        $this->recordReports();
        $this->enablePerConnection();

        $this->deliverSigned($this->messageEnvelope(), 'any-secret', path: '/zapmizer/webhook/' . self::KEY)->assertStatus(500);

        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(QueryException::class, $this->reported[0]);
    }

    public function testTheRouteFollowsTheBootValue()
    {
        $this->assertSame(
            filter_var(getenv('ZAPMIZER_WEBHOOK_PER_CONNECTION') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            Route::has('zapmizer.webhook.connection')
        );
    }

    public function testTheKeyedUrlFollowsThePrefix()
    {
        config()->set('zapmizer.routes.prefix', 'hooks');
        $this->enablePerConnection();

        $this->assertSame('http://localhost/hooks/webhook/' . self::KEY, route('zapmizer.webhook.connection', ['zapmizerWebhookKey' => self::KEY]));
    }

    public function testC39APublishedConfigWithoutTheKeyStaysOff()
    {
        // The `webhook` array of a config/zapmizer.php published before the
        // option existed: the shallow merge keeps it whole.
        config()->set('zapmizer.webhook', ['secret' => null, 'tolerance' => 300]);
        $this->reloadPackageRoutes();

        $this->assertFalse(ConnectionWebhook::enabled());
        $this->assertFalse(Route::has('zapmizer.webhook.connection'));

        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated());

        $this->startConnect()->assertOk();

        $this->assertSame('http://localhost/zapmizer/webhook', $this->sentBody(0)['webhook_url']);
    }

    /** @dataProvider perConnectionValues */
    #[DataProvider('perConnectionValues')]
    public function testWithTheWebhookGroupOffTheConnectAsksForNoWebhook(?string $value, bool $on)
    {
        // A connect-only app (sends through the connected numbers, receives
        // nothing): no public route, so no webhook_url, nor webhook_version,
        // nor a webhook key for the connection.
        $this->addWebhookKeyColumn();
        $this->applyPerConnection($value);
        config()->set('zapmizer.routes.webhook', false);
        $this->reloadPackageRoutes();
        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer(['webhook_id' => null, 'webhook_secret' => null]));

        $this->assertFalse(Route::has('zapmizer.webhook'));
        $this->connect()->assertSee('status: "ok"', false);

        $this->assertArrayNotHasKey('webhook_url', $this->sentBody(0));
        $this->assertArrayNotHasKey('webhook_version', $this->sentBody(0));
        $this->assertNull(DB::table('zapmizer_connections')->value('webhook_key'));
    }

    public function testC40ThePackageRegistersNothing()
    {
        config()->set('zapmizer.routes.enabled', false);
        config()->set('zapmizer.webhook.per_connection', true);
        $this->reloadPackageRoutes();

        $this->assertCount(0, Route::getRoutes());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function appMounts(): array
    {
        return [
            'no keyed route' => ['no keyed route'],
            'with the parameter' => ['with the parameter'],
            'without the parameter' => ['without the parameter'],
        ];
    }

    /** @dataProvider appMounts */
    #[DataProvider('appMounts')]
    public function testC40WithThePackageRoutesOffTheAppMountsItsOwn(string $mount)
    {
        $this->addWebhookKeyColumn();
        config()->set('zapmizer.routes.enabled', false);
        config()->set('zapmizer.webhook.per_connection', true);
        $this->reloadPackageRoutes();

        Route::middleware(['web', 'auth'])->prefix('zapmizer')->name('zapmizer.')->group(__DIR__ . '/../../routes/web.php');

        match ($mount) {
            'no keyed route' => null,
            'with the parameter' => Route::post('hooks/{zapmizerWebhookKey}', [WebhookController::class, 'handleWebhook'])
                ->middleware(VerifyConnectionWebhookSignature::class)
                ->name('zapmizer.webhook.connection'),
            'without the parameter' => Route::post('hooks', [WebhookController::class, 'handleWebhook'])
                ->middleware(VerifyConnectionWebhookSignature::class)
                ->name('zapmizer.webhook.connection'),
        };

        Route::getRoutes()->refreshNameLookups();
        $this->recordReports();
        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer(['webhook_secret' => 'whsec_1']));

        if ($mount === 'no keyed route') {
            $this->startConnect()->assertStatus(500);

            $this->assertCount(1, $this->reported);
            $this->assertStringContainsString('zapmizer.webhook.connection', $this->reported[0]->getMessage());
            $this->assertCount(0, $this->history);

            return;
        }

        $this->connect()->assertSee('status: "ok"', false);
        $key = DB::table('zapmizer_connections')->value('webhook_key');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\z/', (string) $key);

        if ($mount === 'with the parameter') {
            $this->assertSame('http://localhost/hooks/' . $key, $this->sentBody(0)['webhook_url']);
            $this->deliverSigned($this->messageEnvelope(wid: '5581911110000@c.us'), 'whsec_1', path: '/hooks/' . $key)->assertOk();

            return;
        }

        $this->assertSame('http://localhost/hooks?zapmizerWebhookKey=' . $key, $this->sentBody(0)['webhook_url']);
        $this->deliverSigned($this->messageEnvelope(wid: '5581911110000@c.us'), 'whsec_1', path: '/hooks?zapmizerWebhookKey=' . $key)->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function perConnectionOnOff(): array
    {
        return ['single webhook' => [false], 'per connection' => [true]];
    }

    /** @dataProvider perConnectionOnOff */
    #[DataProvider('perConnectionOnOff')]
    public function testT1ConnectWebhookNullKeepsThePre21Behaviour(bool $perConnection)
    {
        $this->addWebhookKeyColumn();
        config()->set('zapmizer.connect.webhook', null);
        config()->set('zapmizer.webhook.per_connection', $perConnection);
        $this->reloadPackageRoutes();
        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer());

        $this->connect()->assertSee('status: "ok"', false);

        $this->assertSame('2026-10-20', $this->sentBody(0)['webhook_version']);

        if ($perConnection) {
            $key = DB::table('zapmizer_connections')->value('webhook_key');
            $this->assertSame('http://localhost/zapmizer/webhook/' . $key, $this->sentBody(0)['webhook_url']);
        } else {
            $this->assertSame('http://localhost/zapmizer/webhook', $this->sentBody(0)['webhook_url']);
        }
    }

    public function testT1ConnectWebhookNullWithTheRouteOffAsksForNothing()
    {
        config()->set('zapmizer.connect.webhook', null);
        config()->set('zapmizer.routes.webhook', false);
        $this->reloadPackageRoutes();
        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer(['webhook_id' => null, 'webhook_secret' => null]));

        $this->connect()->assertSee('status: "ok"', false);

        $this->assertArrayNotHasKey('webhook_url', $this->sentBody(0));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function connectWebhookOff(): array
    {
        return [
            'false word' => ['false', false],
            'zero' => ['0', false],
            'false' => [false, false],
            'false, per connection' => ['false', true],
        ];
    }

    /** @dataProvider connectWebhookOff */
    #[DataProvider('connectWebhookOff')]
    public function testT2ConnectWebhookFalseKeepsTheRouteButAsksForNothing(mixed $value, bool $perConnection)
    {
        $this->addWebhookKeyColumn();
        config()->set('zapmizer.connect.webhook', $value);
        config()->set('zapmizer.webhook.per_connection', $perConnection);
        $this->reloadPackageRoutes();
        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer(['webhook_id' => null, 'webhook_secret' => null]));

        $this->connect()->assertSee('status: "ok"', false);

        $this->assertArrayNotHasKey('webhook_url', $this->sentBody(0));
        $this->assertArrayNotHasKey('webhook_version', $this->sentBody(0));
        $this->assertTrue($this->team()->fresh()->hasActiveZapmizer());
        $this->assertNull(DB::table('zapmizer_connections')->value('webhook_key'));
        $this->assertTrue(Route::has('zapmizer.webhook'));
        $this->deliverUnsigned(['name' => 'verify_number.verified', 'data' => ['number' => '5599888887777']])->assertOk();
    }

    public function testT3ADefensiveTokenWebhookIsDroppedWhenTheConnectAskedForNone()
    {
        Log::spy();
        config()->set('zapmizer.connect.webhook', false);
        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated(), $this->tokenAnswer(['webhook_id' => 42, 'webhook_secret' => null]));

        $this->connect()->assertSee('status: "ok"', false);

        $connection = $this->team()->zapmizerConnection;
        $this->assertNull($connection->webhook_id);
        $this->assertNull($connection->webhook_secret);
        $this->assertNull($connection->webhook_previous_secret);
        Log::shouldNotHaveReceived('warning', ['zapmizer: connected without a webhook secret.', \Mockery::any()]);
    }

    public function testT4ConnectWebhookTrueWithTheRouteOffAsksForTheAppsOwnRoute()
    {
        config()->set('zapmizer.connect.webhook', true);
        config()->set('zapmizer.routes.webhook', false);
        $this->reloadPackageRoutes();
        $this->assertFalse(Route::has('zapmizer.webhook'));

        Route::post('zapmizer/webhook', [WebhookController::class, 'handleWebhook'])->name('zapmizer.webhook');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAsUser();
        $this->fakeHttp($this->sessionCreated());

        $this->startConnect()->assertOk();

        $this->assertSame('http://localhost/zapmizer/webhook', $this->sentBody(0)['webhook_url']);
    }

    public function testT5APendingSessionFromBeforeTheDeployStillFailsWithoutASecret()
    {
        Log::spy();
        config()->set('zapmizer.connect.webhook', false);
        $this->actingAsUser();
        $this->fakeHttp($this->tokenAnswer(['webhook_id' => 42, 'webhook_secret' => null]));

        // No `webhook` key in the state: a session opened before 2.1.
        $this->withSession([ConnectController::SESSION_KEY => [
            'state' => 's', 'connectable' => Team::class . ':' . $this->team()->id, 'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]])->get(route('zapmizer.connect.callback', ['code' => 'c', 'state' => 's']))
            ->assertSee('status: "webhook_failed"', false);

        $this->assertSame(42, $this->team()->zapmizerConnection->webhook_id);
    }
}
