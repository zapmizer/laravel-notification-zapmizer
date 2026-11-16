<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\TestCase;

class InboxRouteConfigTest extends TestCase
{
    use TogglesConnectionWebhook;

    public function testTheInboxRoutesAreOffByDefault()
    {
        $this->assertFalse(Route::has('zapmizer.inbox.session'));
        $this->assertFalse(Route::has('zapmizer.inbox.keepalive'));
        $this->assertTrue(Route::has('zapmizer.connect.start'));
    }

    public function testTheEnvStringTurnsThemOnWithTheConfiguredMiddlewareAndPrefix()
    {
        config()->set('zapmizer.inbox.enabled', 'true');
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
        config()->set('zapmizer.inbox.enabled', 'false');
        $this->reloadPackageRoutes();

        $this->assertFalse(Route::has('zapmizer.inbox.session'));
    }

    public function testRoutesDisabledTakesTheInboxWithThem()
    {
        config()->set('zapmizer.inbox.enabled', true);
        config()->set('zapmizer.routes.enabled', false);
        $this->reloadPackageRoutes();

        $this->assertFalse(Route::has('zapmizer.inbox.session'));
    }
}
