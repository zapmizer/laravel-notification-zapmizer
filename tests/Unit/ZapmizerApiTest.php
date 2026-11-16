<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\ZapmizerApi;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Support\ApiError;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /** @dataProvider failures */
    #[DataProvider('failures')]
    public function testFailureNamesTheExceptionByStatus(int $status, string $class)
    {
        $exception = ZapmizerApi::failure(new Response($status, ['Content-Type' => 'application/json', 'Retry-After' => '5'], '{"message":"Nope."}'));

        $this->assertSame($class, get_class($exception));
        $this->assertSame($status, $exception->status());
        $this->assertSame('Nope.', $exception->reason());
    }

    public static function failures(): array
    {
        return [
            '401' => [401, ZapmizerUnauthorizedException::class],
            '403' => [403, ZapmizerApiException::class],
            '404' => [404, ZapmizerApiException::class],
            '409' => [409, ZapmizerApiException::class],
            '422' => [422, ZapmizerApiException::class],
            '429' => [429, ZapmizerRateLimitedException::class],
        ];
    }

    public function testFailureOn401IsTheTokenRefusal()
    {
        $exception = ZapmizerApi::failure(new Response(401, ['Content-Type' => 'application/json'], '{"message":"Unauthenticated."}'));

        $this->assertSame('Zapmizer refused the connection token.', $exception->getMessage());
        $this->assertSame('Unauthenticated.', $exception->reason());
    }

    public function testFailureOn429CarriesTheRetryAfter()
    {
        $exception = ZapmizerApi::failure(new Response(429, ['Retry-After' => '12'], '{"message":"Too Many Attempts."}'));

        $this->assertSame(12, $exception->retryAfter());
        $this->assertSame('Zapmizer rate-limited the request. Retry in 12 s.', $exception->getMessage());
    }

    public function testFailureWithAnApiErrorDoesNotReadTheBodyAgain()
    {
        $error = ApiError::from(new Response(409, ['Content-Type' => 'application/json'], '{"error":"window_closed"}'));

        $exception = ZapmizerApi::failure($error);

        $this->assertSame($error, $exception->apiError());
        $this->assertSame('window_closed', $exception->error());
    }
}
