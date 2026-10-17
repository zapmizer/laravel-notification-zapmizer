<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
}
