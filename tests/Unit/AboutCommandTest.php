<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Artisan;
use NotificationChannels\Zapmizer\Test\TestCase;

class AboutCommandTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('zapmizer.base_uri', 'https://app.zapmizer.com/api/');
        $app['config']->set('zapmizer.api_version', '2026-10-20');
        $app['config']->set('zapmizer.api_token', 'secret-token');
        $app['config']->set('zapmizer.partner.id', 'partner-id');
        $app['config']->set('zapmizer.partner.secret', null);
        $app['config']->set('zapmizer.webhook.secret', 'whsec');
        $app['config']->set('zapmizer.routes.inbox', false);
        $app['config']->set('zapmizer.routes.verify_number', false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function about(): array
    {
        Artisan::call('about', ['--only' => 'zapmizer', '--json' => true]);

        return json_decode(Artisan::output(), true)['zapmizer'];
    }

    public function testTheAboutCommandShowsTheZapmizerSection()
    {
        $about = $this->about();

        $this->assertSame('https://app.zapmizer.com/api/', $about['base_uri']);
        $this->assertSame('2026-10-20', $about['api_version']);
        $this->assertSame('connect, webhook', $about['routes']);
        $this->assertArrayHasKey('version', $about);
        $this->assertArrayHasKey('transport', $about);
    }

    public function testSecretsShowOnlyWhetherTheyAreSet()
    {
        $about = $this->about();

        $this->assertTrue($about['api_token']);
        $this->assertFalse($about['partner']);
        $this->assertTrue($about['webhook_secret']);
        $this->assertFalse($about['verify_number_webhook_secret']);
        $this->assertStringNotContainsString('secret-token', json_encode($about));
        $this->assertStringNotContainsString('whsec', json_encode($about));
    }

    public function testRoutesShowOffWithTheMasterSwitchOff()
    {
        config(['zapmizer.routes.enabled' => false]);

        $this->assertSame('off', $this->about()['routes']);
    }
}
