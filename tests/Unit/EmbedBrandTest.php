<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Support\EmbedBrand;
use NotificationChannels\Zapmizer\Test\TestCase;

class EmbedBrandTest extends TestCase
{
    public function testTheBrandFollowsTheDeployOfTheBaseUri()
    {
        $cases = [
            'https://app.zapmizer.com/api/' => 'zapmizer',
            'https://app.parlichat.com/api/' => 'parli',
            'https://APP.ParliChat.com/api' => 'parli',
            'https://parlichat.com/api/' => 'parli',
            'https://staging.app.parlichat.com/api/' => 'parli',
            'https://notparlichat.com/api/' => 'zapmizer',
            'https://parlichat.com.evil.test/api/' => 'zapmizer',
            'http://localhost:8001/api/' => 'zapmizer',
            'not a url' => 'zapmizer',
        ];

        foreach ($cases as $baseUri => $brand) {
            $this->assertSame($brand, EmbedBrand::fromBaseUri($baseUri), $baseUri);
        }

        $this->assertSame('zapmizer', EmbedBrand::fromBaseUri(null));
    }

    public function testResolveReadsTheConfiguredBaseUri()
    {
        $this->assertSame('zapmizer', EmbedBrand::resolve(), 'the default base_uri is app.zapmizer.com');

        config()->set('zapmizer.base_uri', 'https://app.parlichat.com/api/');

        $this->assertSame('parli', EmbedBrand::resolve());
    }

    public function testTheConfiguredBrandWins()
    {
        config()->set('zapmizer.base_uri', 'https://app.parlichat.com/api/');
        config()->set('zapmizer.inbox.brand', ' acme ');

        $this->assertSame('acme', EmbedBrand::resolve());

        config()->set('zapmizer.inbox.brand', '');

        $this->assertSame('parli', EmbedBrand::resolve(), 'empty falls back to the base_uri');
    }
}
