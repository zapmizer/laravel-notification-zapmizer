<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\ZapmizerApi;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;

class ZapmizerApiTest extends TestCase
{
    public function testC15JsonAnswerIsReturnedAndUrlPassedAsIs()
    {
        $transport = new RecordingTransport(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $response = (new ZapmizerApi($transport))->send('GET', 'http://zap.test/api/x', ['query' => ['a' => 1]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('http://zap.test/api/x', $transport->calls[0]['url']);
        $this->assertSame(['a' => 1], $transport->calls[0]['options']['query']);
        $this->assertSame('application/json', $transport->calls[0]['options']['headers']['Accept']);
    }

    public function testC23FixedHeadersWinCaseInsensitively()
    {
        $transport = new RecordingTransport(new Response(200, [], '{}'));

        (new ZapmizerApi($transport))->send('GET', 'http://zap.test/api/x', [
            'headers' => ['x-partner-key' => 'evil', 'accept' => 'text/html', 'X-Other' => 'kept'],
        ], ['X-Partner-Key' => 'id|secret']);

        $this->assertSame([
            'X-Other' => 'kept',
            'Accept' => 'application/json',
            'X-Partner-Key' => 'id|secret',
        ], $transport->calls[0]['options']['headers']);
    }

    public function testC16HtmlAnswerIsUnexpected()
    {
        $transport = new RecordingTransport(new Response(200, ['Content-Type' => 'text/html'], '<html>'));

        $this->expectException(ZapmizerConnectException::class);

        (new ZapmizerApi($transport))->send('GET', 'http://zap.test/api/x');
    }

    public function testC17RedirectIsUnexpected()
    {
        $transport = new RecordingTransport(new Response(302, ['Location' => 'http://zap.test/login']));

        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('redirected (302 to http://zap.test/login)');

        (new ZapmizerApi($transport))->send('GET', 'http://zap.test/api/x');
    }

    public function testC18ClientErrorsAreReturnedForTheCallerToDecide()
    {
        foreach ([401, 403, 404, 422, 429] as $status) {
            $transport = new RecordingTransport(new Response($status, ['Content-Type' => 'text/html'], '<html>'));

            $this->assertSame($status, (new ZapmizerApi($transport))->send('GET', 'http://zap.test/api/x')->getStatusCode());
        }
    }

    public function testC19ServerErrorsAreUnavailable()
    {
        $transport = new RecordingTransport(new Response(502, [], 'bad gateway'));

        $this->expectException(ZapmizerUnavailableException::class);

        (new ZapmizerApi($transport))->send('GET', 'http://zap.test/api/x');
    }

    public function testBinaryEndpointOnlyChecksTheRedirect()
    {
        $binary = new RecordingTransport(new Response(200, ['Content-Type' => 'image/png'], 'png'));
        $redirect = new RecordingTransport(new Response(302, ['Location' => 'http://zap.test/login']));

        $this->assertSame('png', (string) (new ZapmizerApi($binary))->send('GET', 'http://zap.test/api/m', [], [], false)->getBody());

        $this->expectException(ZapmizerConnectException::class);

        (new ZapmizerApi($redirect))->send('GET', 'http://zap.test/api/m', [], [], false);
    }
}
