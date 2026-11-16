<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\TestCase;

class InboxRouteConfigTest extends TestCase
{
    use TogglesConnectionWebhook;

    protected function defineEnvironment($app)
    {
        // As the package config has it by default.
        $app['config']->set('zapmizer.routes.inbox', false);
    }

    public function testTheInboxRoutesAreOffByDefault()
    {
        $this->assertFalse(Route::has('zapmizer.inbox.session'));
        $this->assertFalse(Route::has('zapmizer.inbox.keepalive'));
        $this->assertTrue(Route::has('zapmizer.connect.start'), 'the other groups do not depend on it');
    }

    public function testTheEnvStringTurnsThemOnWithTheConfiguredMiddlewareAndPrefix()
    {
        config()->set('zapmizer.routes.inbox', 'true');
        config()->set('zapmizer.inbox.middleware', ['web', 'auth:admin']);
        config()->set('zapmizer.routes.prefix', 'whatsapp');
        $this->reloadPackageRoutes();

        $route = Route::getRoutes()->getByName('zapmizer.inbox.session');

        $this->assertSame('whatsapp/inbox/session', $route->uri());
        $this->assertContains('auth:admin', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
    }

    public function testAFalseStringKeepsThemOff()
    {
        config()->set('zapmizer.routes.inbox', 'false');
        $this->reloadPackageRoutes();

        $this->assertFalse(Route::has('zapmizer.inbox.session'));
    }

    public function testTheOldInboxEnabledKeyNoLongerTurnsThemOn()
    {
        // `inbox.enabled` (ZAPMIZER_INBOX_ENABLED, until 2.0.0-beta.3) gave
        // way to `routes.inbox`: an app that used the name for something
        // else gets no route from it.
        config()->set('zapmizer.inbox.enabled', true);
        $this->reloadPackageRoutes();

        $this->assertFalse(Route::has('zapmizer.inbox.session'));
    }

    public function testRoutesDisabledTakesTheInboxWithThem()
    {
        config()->set('zapmizer.routes.inbox', true);
        config()->set('zapmizer.routes.enabled', false);
        $this->reloadPackageRoutes();

        $this->assertFalse(Route::has('zapmizer.inbox.session'));
    }
}
