<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Support\BaseUri;
use NotificationChannels\Zapmizer\Test\TestCase;

class BaseUriTest extends TestCase
{
    public function testABaseUriIsAnAbsoluteUrlEndingInApi()
    {
        foreach (['https://app.zapmizer.com/api/', 'https://app.parlichat.com/api', 'http://localhost:8001/api/', 'https://zap.test/v1/api'] as $uri) {
            $this->assertNull(BaseUri::problem($uri), $uri);
        }

        $this->assertStringContainsString('/api', BaseUri::problem('https://app.parlichat.com/'));
        $this->assertStringContainsString('/api', BaseUri::problem('https://app.parlichat.com/api/v2'));
        $this->assertStringContainsString('/api', BaseUri::problem('https://app.parlichat.com/apis'));
        $this->assertStringContainsString('absoluta', BaseUri::problem('app.parlichat.com/api'));
        $this->assertStringContainsString('absoluta', BaseUri::problem(''));
        $this->assertStringContainsString('absoluta', BaseUri::problem(null));
    }

    public function testSameIgnoresTheTrailingSlash()
    {
        $this->assertTrue(BaseUri::same('https://app.parlichat.com/api', 'https://app.parlichat.com/api/'));
        $this->assertFalse(BaseUri::same('https://app.parlichat.com/api', 'https://app.zapmizer.com/api'));
    }

    public function testTheBrandOfABaseUriBeingWritten()
    {
        $this->assertSame('parli', \NotificationChannels\Zapmizer\Support\EmbedBrand::resolveFor('https://app.parlichat.com/api/'));

        config()->set('zapmizer.inbox.brand', 'acme');

        $this->assertSame('acme', \NotificationChannels\Zapmizer\Support\EmbedBrand::resolveFor('https://app.parlichat.com/api/'));
    }
}
