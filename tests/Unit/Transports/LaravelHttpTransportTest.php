<?php

namespace NotificationChannels\Zapmizer\Test\Unit\Transports;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\TestCase;
use RuntimeException;
use Throwable;

class LaravelHttpTransportTest extends TestCase
{
    public function testReturnsPsrResponseWithStatusHeadersAndBody()
    {
        Http::fake(['zap.test/*' => Http::response(['ok' => true], 201, ['Retry-After' => '7'])]);

        $response = (new LaravelHttpTransport())->send('POST', 'http://zap.test/api/x', [
            'headers' => ['X-Partner-Key' => 'id|secret'],
            'json' => ['state' => 's'],
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('7', $response->getHeaderLine('Retry-After'));
        $this->assertSame(['ok' => true], json_decode((string) $response->getBody(), true));
        Http::assertSent(fn (Request $request) => $request->url() === 'http://zap.test/api/x'
            && $request->header('X-Partner-Key')[0] === 'id|secret'
            && $request['state'] === 's');
    }

    public function testC17DoesNotFollowRedirects()
    {
        Http::fake(['zap.test/*' => Http::response('', 302, ['Location' => 'http://zap.test/login'])]);

        $this->assertSame(302, (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x')->getStatusCode());
    }

    public function testForcedOptionsWin()
    {
        Http::fake(['zap.test/*' => Http::response('{}', 404)]);

        $response = (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x', ['http_errors' => true]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSinkWritesTheBody()
    {
        $sink = tempnam(sys_get_temp_dir(), 'zt-');
        Http::fake(['zap.test/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);

        (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/media', ['sink' => $sink]);

        $this->assertSame('bytes', file_get_contents($sink));
        @unlink($sink);
    }

    public function testC20ConnectionFailureBecomesUnavailable()
    {
        Http::fake(fn () => throw new ConnectException('down', new PsrRequest('GET', 'http://zap.test')));

        $this->expectException(ZapmizerUnavailableException::class);

        (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x');
    }

    public function testC21RequestExceptionFromBelowBecomesUnavailableInEveryVersion()
    {
        Http::fake(fn () => throw new RequestException('cut', new PsrRequest('GET', 'http://zap.test'), new PsrResponse(200)));

        $this->expectException(ZapmizerUnavailableException::class);

        (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x');
    }

    public function testC22UnencodableJsonBecomesUnavailable()
    {
        Http::fake(['zap.test/*' => Http::response('{}', 200)]);

        $this->expectException(ZapmizerUnavailableException::class);

        (new LaravelHttpTransport())->send('POST', 'http://zap.test/api/x', ['json' => ['a' => "\xB1"]]);
    }

    public function testF3FakeAppliedAfterConstructionStillApplies()
    {
        $transport = new LaravelHttpTransport();
        Http::fake(['zap.test/*' => Http::response('{"late":true}', 200)]);

        $this->assertSame('{"late":true}', (string) $transport->send('GET', 'http://zap.test/api/x')->getBody());
    }

    public function testNeedsALaravelApplication()
    {
        $app = Facade::getFacadeApplication();
        Facade::clearResolvedInstance(Factory::class);
        Facade::setFacadeApplication(null);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('LaravelHttpTransport needs a Laravel application.');

            (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x');
        } finally {
            Facade::setFacadeApplication($app);
        }
    }

    public function testStrayRequestExceptionPassesThrough()
    {
        if (!method_exists(Factory::class, 'preventStrayRequests')) {
            $this->markTestSkipped('preventStrayRequests exists from Laravel 9 on.');
        }

        Http::preventStrayRequests();
        Http::fake(['other.test/*' => Http::response('{}')]);

        $caught = null;

        try {
            (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertNotInstanceOf(ZapmizerUnavailableException::class, $caught);
        $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $caught);
    }
}
