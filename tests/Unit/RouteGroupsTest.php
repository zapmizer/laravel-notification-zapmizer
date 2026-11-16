<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Support\RouteGroups;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\ZapmizerServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Each group of package routes is registered only when switched on
 * (`zapmizer.routes.{group}`), under the `zapmizer.routes.enabled` master
 * switch. The suite runs with every group on (phpunit.xml.dist); these tests
 * switch them off as the package config does by default.
 */
class RouteGroupsTest extends TestCase
{
    use TogglesConnectionWebhook;

    public const ENVS = [
        'connect' => 'ZAPMIZER_ROUTES_CONNECT',
        'webhook' => 'ZAPMIZER_ROUTES_WEBHOOK',
        'inbox' => 'ZAPMIZER_ROUTES_INBOX',
        'verify_number' => 'ZAPMIZER_ROUTES_VERIFY_NUMBER',
    ];

    public const ROUTES = [
        'connect' => ['zapmizer.connect.show', 'zapmizer.connect.destroy', 'zapmizer.connect.start', 'zapmizer.connect.callback', 'zapmizer.connect.reconnect', 'zapmizer.connect.reconnect.callback'],
        'webhook' => ['zapmizer.webhook', 'zapmizer.webhook.connection'],
        'inbox' => ['zapmizer.inbox.session', 'zapmizer.inbox.keepalive'],
        'verify_number' => ['zapmizer.verify_number'],
    ];

    protected function defineEnvironment($app)
    {
        foreach (array_keys(self::ENVS) as $group) {
            $app['config']->set("zapmizer.routes.{$group}", false);
        }

        // The per-connection route belongs to the webhook group.
        $app['config']->set('zapmizer.webhook.per_connection', true);
    }

    /**
     * @return array<int, string>
     */
    protected function packageRouteNames(): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'zapmizer.') || str_starts_with($route->uri(), 'zapmizer')) {
                $names[] = (string) $route->getName();
            }
        }

        sort($names);

        return $names;
    }

    public function testWithTheDefaultConfigTheAppGetsNoPackageRoute()
    {
        $this->assertSame([], $this->packageRouteNames());

        Artisan::call('route:list', ['--json' => true]);

        // Only the routes themselves: the JSON also carries file paths (the
        // Laravel 12 storage route points into vendor/, under this repo's name).
        $routes = json_decode(Artisan::output(), true) ?: [];
        $ours = array_filter($routes, fn (array $route) => str_contains(strtolower((string) ($route['uri'] ?? '')), 'zapmizer')
            || str_starts_with((string) ($route['name'] ?? ''), 'zapmizer.')
            || str_contains((string) ($route['action'] ?? ''), 'NotificationChannels\\Zapmizer'));
        $this->assertSame([], array_values($ours));
    }

    public function testTheGroupsAreOffInThePackageConfigWithoutTheirEnv()
    {
        $saved = [];

        foreach (self::ENVS as $env) {
            $saved[$env] = getenv($env);
            putenv($env);
            unset($_ENV[$env], $_SERVER[$env]);
        }

        try {
            $routes = (require __DIR__ . '/../../config/zapmizer.php')['routes'];
        } finally {
            foreach ($saved as $env => $value) {
                if ($value !== false) {
                    putenv("{$env}={$value}");
                    $_ENV[$env] = $_SERVER[$env] = $value;
                }
            }
        }

        foreach (array_keys(self::ENVS) as $group) {
            $this->assertFalse($routes[$group], $group);
        }

        $this->assertTrue($routes['enabled'], 'the master switch stays on: the groups decide');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function routeGroups(): array
    {
        return array_combine(array_keys(self::ROUTES), array_map(fn (string $group) => [$group], array_keys(self::ROUTES)));
    }

    /** @dataProvider routeGroups */
    #[DataProvider('routeGroups')]
    public function testEachGroupRegistersOnlyItsRoutes(string $group)
    {
        config()->set("zapmizer.routes.{$group}", 'true');
        $this->reloadPackageRoutes();

        $expected = self::ROUTES[$group];
        sort($expected);

        $this->assertSame($expected, $this->packageRouteNames());
    }

    public function testTheMasterSwitchTakesEveryGroupWithIt()
    {
        foreach (array_keys(self::ENVS) as $group) {
            config()->set("zapmizer.routes.{$group}", true);
        }

        config()->set('zapmizer.routes.enabled', false);
        $this->reloadPackageRoutes();

        $this->assertSame([], $this->packageRouteNames());
    }

    public function testAFalseStringKeepsAGroupOff()
    {
        config()->set('zapmizer.routes.connect', 'false');
        $this->reloadPackageRoutes();

        $this->assertSame([], $this->packageRouteNames());
    }

    public function testAPublishedConfigWithoutTheGroupKeysGetsThemFromThePackage()
    {
        // config/zapmizer.php published by 0.x: `routes` without the group
        // keys. The config merge is shallow, so the provider fills them in.
        config()->set('zapmizer.routes', ['enabled' => true, 'prefix' => 'whatsapp', 'middleware' => ['web']]);
        $saved = getenv('ZAPMIZER_ROUTES_CONNECT');
        putenv('ZAPMIZER_ROUTES_CONNECT=true');

        try {
            (fn () => $this->mergeRouteGroups())->call($this->app->getProvider(ZapmizerServiceProvider::class));
        } finally {
            putenv($saved === false ? 'ZAPMIZER_ROUTES_CONNECT' : "ZAPMIZER_ROUTES_CONNECT={$saved}");
        }

        $this->assertSame('whatsapp', config('zapmizer.routes.prefix'), 'the app keeps its keys');
        $this->assertSame(['web'], config('zapmizer.routes.middleware'));
        $this->assertTrue(RouteGroups::switchedOn(RouteGroups::CONNECT), 'the env of a missing key holds');
        $this->assertArrayHasKey('webhook_middleware', config('zapmizer.routes'));
        $this->assertArrayHasKey('verify_number', config('zapmizer.routes'));
    }
}
