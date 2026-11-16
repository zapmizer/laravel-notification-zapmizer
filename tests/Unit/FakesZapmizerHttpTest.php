<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\TestCase;

class FakesZapmizerHttpTest extends TestCase
{
    use FakesZapmizerHttp;

    protected array $history = [];

    public function testRecordsTheRequestAndTheOptionsAfterTheMockAnswers()
    {
        $this->fakeZapmizer(new MockHandler([new Response(201, [], '{"ok":true}')]));

        $response = (new LaravelHttpTransport())->send('POST', 'http://zap.test/api/x', ['json' => ['a' => 1], 'timeout' => 7.0]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://zap.test/api/x', (string) $request->getUri());
        $this->assertSame('{"a":1}', (string) $request->getBody());
        $this->assertSame(7.0, $this->history[0]['options']['timeout']);
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testRecordsAFailedCallAndTheFailureReachesTheTransport()
    {
        $this->fakeZapmizer(new MockHandler([new ConnectException('down', new Request('GET', 'http://zap.test'))]));

        try {
            (new LaravelHttpTransport())->send('GET', 'http://zap.test/api/x');
            $this->fail('expected ZapmizerUnavailableException');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertCount(1, $this->history);
        }
    }
}
