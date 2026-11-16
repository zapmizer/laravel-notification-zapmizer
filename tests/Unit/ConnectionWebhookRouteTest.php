<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyConnectionWebhookSignature;
use NotificationChannels\Zapmizer\Support\ConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\DrivesTheConnectRoutes;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesFirstTeam;
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
}
