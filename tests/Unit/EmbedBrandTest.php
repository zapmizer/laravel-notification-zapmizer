<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function testOnlyTheKnownHostsAreKnownDeploys()
    {
        $cases = [
            'https://app.zapmizer.com/api/' => 'zapmizer',
            'https://app.parlichat.com/api/' => 'parli',
            'https://staging.app.parlichat.com/api/' => 'parli',
            'http://localhost:8001/api/' => null,
            'https://staging.zapmizer.com/api/' => null,
            'https://parlichat.com.evil.test/api/' => null,
            'not a url' => null,
        ];

        foreach ($cases as $baseUri => $brand) {
            $this->assertSame($brand, EmbedBrand::knownDeploy($baseUri), $baseUri);
        }
    }

    public function testTheManifestIsAtTheWebRootOfTheDeploy()
    {
        $this->assertSame('http://localhost:8001/brand/manifest.json', EmbedBrand::manifestUrl('http://localhost:8001/api/'));
        $this->assertSame('https://app.parlichat.com/brand/manifest.json', EmbedBrand::manifestUrl('https://app.parlichat.com/api'));
        $this->assertSame('https://example.com/zap/brand/manifest.json', EmbedBrand::manifestUrl('https://example.com/zap/api/'));
        $this->assertNull(EmbedBrand::manifestUrl('not a url'));
    }

    public function testTheDeployTellsItsBrandInTheManifest()
    {
        Http::fake([
            'parli.test:8001/brand/manifest.json' => Http::response(['name' => 'Parli', 'short_name' => 'Parli']),
            'zap.test/brand/manifest.json' => Http::response(['name' => 'Zapmizer']),
            'acme.test/brand/manifest.json' => Http::response(['name' => 'Acme']),
            'html.test/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
            'gone.test/*' => Http::response('', 404),
            'down.test/*' => fn () => throw new ConnectionException('down'),
        ]);

        $this->assertSame('parli', EmbedBrand::discover('http://parli.test:8001/api/'));
        $this->assertSame('zapmizer', EmbedBrand::discover('http://zap.test/api/'));
        $this->assertNull(EmbedBrand::discover('http://acme.test/api/'), 'a brand the package does not know');
        $this->assertNull(EmbedBrand::discover('http://html.test/api/'));
        $this->assertNull(EmbedBrand::discover('http://gone.test/api/'));
        $this->assertNull(EmbedBrand::discover('http://down.test/api/'));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'http://parli.test:8001/brand/manifest.json');
    }

    public function testTheNameIsTheOnePeopleRead()
    {
        $this->assertSame('Parli', EmbedBrand::name('parli'));
        $this->assertSame('Zapmizer', EmbedBrand::name('zapmizer'));
        $this->assertSame('Acme', EmbedBrand::name('acme'));

        config()->set('zapmizer.base_uri', 'https://app.parlichat.com/api/');
        $this->assertSame('Parli', EmbedBrand::name());
    }
}
