<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InstanceClientRevokeTest extends TestCase
{
    use AssertsContract;

    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): InstanceClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new InstanceClient('team-token', new GuzzleTransport(new HttpClient(['handler' => $stack])), 'http://localhost/api', '2025-06-27');
    }

    /** @dataProvider answersThatMeanRevokedFromTheContract */
    #[DataProvider('answersThatMeanRevokedFromTheContract')]
    public function testV1RevokedNowOrAlreadyRevokedReturns(int $status, string $body, array $headers)
    {
        $this->assertMatchesContract('DELETE', '/connect/token', $status, $body);

        $this->makeClient(new MockHandler([new Response($status, $headers, $body)]))->revokeToken();

        $request = $this->history[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('http://localhost/api/connect/token', (string) $request->getUri());
        $this->assertSame('Bearer team-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('2025-06-27', $request->getHeaderLine('api-version'));
        $this->assertSame('', (string) $request->getBody());
    }

    public static function answersThatMeanRevokedFromTheContract(): array
    {
        return [
            'V1 204' => [204, '', []],
            'V1 401' => [401, '{"message":"Unauthenticated."}', ['Content-Type' => 'application/json']],
        ];
    }

    public function testV2ATokenThatIsNotFromAConnectIsAnApiExceptionWithTheStatus()
    {
        $body = '{"message":"Este token não veio de um connect."}';
        $this->assertMatchesContract('DELETE', '/connect/token', 404, $body);

        try {
            $this->makeClient(new MockHandler([new Response(404, ['Content-Type' => 'application/json'], $body)]))->revokeToken();
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(ZapmizerApiException::class, get_class($exception));
            $this->assertSame(404, $exception->status());
            $this->assertSame('Este token não veio de um connect.', $exception->reason());
        }
    }

    /** @dataProvider failuresOutsideTheContract */
    #[DataProvider('failuresOutsideTheContract')]
    public function testV3AFailureOutsideTheContractIsAnException(mixed $answer, string $class)
    {
        $this->expectException($class);

        $this->makeClient(new MockHandler([$answer]))->revokeToken();
    }

    public static function failuresOutsideTheContract(): array
    {
        $json = ['Content-Type' => 'application/json'];

        return [
            'V3 429' => [new Response(429, $json + ['Retry-After' => '5'], '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class],
            'V3 500' => [new Response(500, [], 'boom'), ZapmizerUnavailableException::class],
            'V3 network' => [new ConnectException('timed out', new Request('DELETE', 'connect/token')), ZapmizerUnavailableException::class],
            'V3 422' => [new Response(422, $json, '{"message":"Invalid."}'), ZapmizerApiException::class],
            '403' => [new Response(403, $json, '{"message":"Your email address is not verified."}'), ZapmizerApiException::class],
        ];
    }

    /** @dataProvider answersThatAreNotARevocation */
    #[DataProvider('answersThatAreNotARevocation')]
    public function testV4AnAnswerThatIsNotARevocationIsAnUnexpectedResponse(Response $response)
    {
        try {
            $this->makeClient(new MockHandler([$response]))->revokeToken();
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertNotInstanceOf(ZapmizerApiException::class, $exception);
            $this->assertStringContainsString('unexpected response', $exception->getMessage());
        }
    }

    public static function answersThatAreNotARevocation(): array
    {
        return [
            'V4 200' => [new Response(200, ['Content-Type' => 'application/json'], '{"message":"ok"}')],
            'V4 302' => [new Response(302, ['Location' => 'http://localhost/login'])],
        ];
    }

    public function testV4A204InHtmlStillReturns()
    {
        $this->makeClient(new MockHandler([new Response(204, ['Content-Type' => 'text/html'], '')]))->revokeToken();

        $this->addToAssertionCount(1);
    }
}
