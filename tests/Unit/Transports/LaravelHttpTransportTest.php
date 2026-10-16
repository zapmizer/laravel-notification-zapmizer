<?php

namespace NotificationChannels\Zapmizer\Test\Unit\Transports;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\TestCase;
use RuntimeException;
use Throwable;

class LaravelHttpTransportTest extends TestCase
{
    use FakesZapmizerHttp;

    protected array $history = [];

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
        $this->fakeZapmizer(new MockHandler([new PsrResponse(404, [], '{}')]));

        $response = (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x', ['http_errors' => true, 'allow_redirects' => true]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->history[0]['options']['http_errors']);
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testPassesOptionsThrough()
    {
        $this->fakeZapmizer(new MockHandler([new PsrResponse(200, [], 'bytes')]));

        (new LaravelHttpTransport())->send('POST', 'http://zap.test/api/x', [
            'headers' => ['X-A' => '1'],
            'json' => ['a' => 1],
            'query' => ['q' => 'v'],
            'stream' => false,
            'timeout' => 3.0,
            'connect_timeout' => 2.0,
        ]);

        $options = $this->history[0]['options'];
        $request = $this->history[0]['request'];
        $this->assertSame('1', $request->getHeaderLine('X-A'));
        $this->assertSame('{"a":1}', (string) $request->getBody());
        $this->assertSame('q=v', $request->getUri()->getQuery());
        $this->assertFalse($options['stream']);
        $this->assertSame(3.0, $options['timeout']);
        $this->assertSame(2.0, $options['connect_timeout']);
    }

    public function testConstructorTimeoutsAreDefaultsTheCallCanOverride()
    {
        $this->fakeZapmizer(new MockHandler([new PsrResponse(200), new PsrResponse(200)]));
        $transport = new LaravelHttpTransport(5.0, 10.0);

        $transport->send('GET', 'http://zap.test/api/x');
        $transport->send('GET', 'http://zap.test/api/x', ['timeout' => 600, 'connect_timeout' => 60]);

        $this->assertSame(5.0, $this->history[0]['options']['connect_timeout']);
        $this->assertSame(10.0, $this->history[0]['options']['timeout']);
        $this->assertSame(60, $this->history[1]['options']['connect_timeout']);
        $this->assertSame(600, $this->history[1]['options']['timeout']);
    }

    public function testNullTimeoutsSendNothingOfTheLib()
    {
        $defaults = (new PendingRequest())->getOptions();
        $this->fakeZapmizer(new MockHandler([new PsrResponse(200)]));

        (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x');

        $options = $this->history[0]['options'];
        $this->assertSame($defaults['connect_timeout'] ?? null, $options['connect_timeout'] ?? null);
        $this->assertSame($defaults['timeout'] ?? null, $options['timeout'] ?? null);
    }

    public function testC20ConnectionFailureBecomesUnavailable()
    {
        $failure = new ConnectException('down', new PsrRequest('GET', 'http://zap.test'));
        Http::fake(fn () => throw $failure);

        try {
            (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x');
            $this->fail('expected ZapmizerUnavailableException');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
            $this->assertSame($failure, $exception->getPrevious()->getPrevious());
        }
    }

    public function testC21RequestExceptionFromBelowBecomesUnavailableInEveryVersion()
    {
        Http::fake(fn () => throw new RequestException('cut', new PsrRequest('GET', 'http://zap.test'), new PsrResponse(200)));

        $this->expectException(ZapmizerUnavailableException::class);

        (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x');
    }

    public function testC22UnencodableJsonIsTheCallersError()
    {
        Http::fake(['zap.test/*' => Http::response('{}', 200)]);

        try {
            (new LaravelHttpTransport())->send('POST', 'http://zap.test/api/x', ['json' => ['a' => "\xB1"]]);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\GuzzleHttp\Exception\InvalidArgumentException $e) {
            $this->assertInstanceOf(\GuzzleHttp\Exception\InvalidArgumentException::class, $e);
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertNotInstanceOf(ZapmizerConnectException::class, $e);
            $this->assertNotInstanceOf(ZapmizerException::class, $e);
        }
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
