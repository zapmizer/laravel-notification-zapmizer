<?php

namespace NotificationChannels\Zapmizer\Test\Unit\Transports;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\TestCase;

class GuzzleTransportTest extends TestCase
{
    protected array $history = [];

    protected function client(array $queue): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    public function testForcesErrorsAndRedirectsOff()
    {
        $transport = new GuzzleTransport($this->client([new Response(404, [], '{}')]));

        $response = $transport->send('GET', 'http://zap.test/api/x', ['http_errors' => true, 'allow_redirects' => true]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->history[0]['options']['http_errors']);
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testC17ReturnsRedirectsAsTheyCame()
    {
        $transport = new GuzzleTransport($this->client([new Response(302, ['Location' => 'http://zap.test/login'])]));

        $this->assertSame(302, $transport->send('GET', 'http://zap.test/api/x')->getStatusCode());
    }

    public function testPassesOptionsThrough()
    {
        $sink = tempnam(sys_get_temp_dir(), 'zt-');
        $transport = new GuzzleTransport($this->client([new Response(200, [], 'bytes')]));

        $transport->send('POST', 'http://zap.test/api/x', [
            'headers' => ['X-A' => '1'],
            'json' => ['a' => 1],
            'query' => ['q' => 'v'],
            'sink' => $sink,
            'stream' => false,
            'timeout' => 3.0,
            'connect_timeout' => 2.0,
        ]);

        $options = $this->history[0]['options'];
        $request = $this->history[0]['request'];
        $this->assertSame('1', $request->getHeaderLine('X-A'));
        $this->assertSame('{"a":1}', (string) $request->getBody());
        $this->assertSame('q=v', $request->getUri()->getQuery());
        $this->assertSame($sink, $options['sink']);
        $this->assertFalse($options['stream']);
        $this->assertSame(3.0, $options['timeout']);
        $this->assertSame(2.0, $options['connect_timeout']);
        $this->assertSame('bytes', file_get_contents($sink));
        @unlink($sink);
    }

    public function testConstructorTimeoutsAreDefaultsTheCallCanOverride()
    {
        $transport = new GuzzleTransport($this->client([new Response(200), new Response(200)]), 5.0, 10.0);

        $transport->send('GET', 'http://zap.test/api/x');
        $transport->send('GET', 'http://zap.test/api/x', ['timeout' => 600, 'connect_timeout' => 60]);

        $this->assertSame(5.0, $this->history[0]['options']['connect_timeout']);
        $this->assertSame(10.0, $this->history[0]['options']['timeout']);
        $this->assertSame(60, $this->history[1]['options']['connect_timeout']);
        $this->assertSame(600, $this->history[1]['options']['timeout']);
    }

    public function testNullTimeoutsAreNotSent()
    {
        $transport = new GuzzleTransport($this->client([new Response(200)]));

        $transport->send('GET', 'http://zap.test/api/x');

        $this->assertArrayNotHasKey('timeout', $this->history[0]['options']);
        $this->assertArrayNotHasKey('connect_timeout', $this->history[0]['options']);
    }

    public function testC20NetworkFailureBecomesUnavailable()
    {
        $failure = new ConnectException('down', new Request('GET', 'http://zap.test'));
        $transport = new GuzzleTransport($this->client([$failure]));

        try {
            $transport->send('GET', 'http://zap.test/api/x');
            $this->fail('expected ZapmizerUnavailableException');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testC21CutBodyBecomesUnavailable()
    {
        $request = new Request('GET', 'http://zap.test');
        $transport = new GuzzleTransport($this->client([new RequestException('cut', $request, new Response(200))]));

        $this->expectException(ZapmizerUnavailableException::class);

        $transport->send('GET', 'http://zap.test/api/x');
    }

    public function testC22UnencodableJsonBecomesUnavailable()
    {
        $transport = new GuzzleTransport($this->client([new Response(200)]));

        $this->expectException(ZapmizerUnavailableException::class);

        $transport->send('POST', 'http://zap.test/api/x', ['json' => ['a' => "\xB1"]]);
    }

    public function testC9WithClientKeepsTimeoutsAndSubclass()
    {
        $original = new class (null, 5.0, 10.0) extends GuzzleTransport {
        };
        $client = $this->client([new Response(200)]);

        $swapped = $original->withClient($client);
        $swapped->send('GET', 'http://zap.test/api/x');

        $this->assertInstanceOf(get_class($original), $swapped);
        $this->assertSame($client, $swapped->client());
        $this->assertNotSame($client, $original->client());
        $this->assertSame(10.0, $this->history[0]['options']['timeout']);
    }

    public function testDefaultsToANewGuzzleClient()
    {
        $this->assertInstanceOf(Client::class, (new GuzzleTransport())->client());
    }
}
