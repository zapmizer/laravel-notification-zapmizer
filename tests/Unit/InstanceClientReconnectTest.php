<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\ReconnectResult;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InstanceClientReconnectTest extends TestCase
{
    use AssertsContract;

    private const NEEDS_RECONNECT_MESSAGE = 'O número precisa ser reconectado pelo cliente.';

    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): InstanceClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new InstanceClient('team-token', new GuzzleTransport(new HttpClient(['handler' => $stack])), 'http://localhost/api', '2025-06-27');
    }

    protected function reconnectWith(Response $response): ReconnectResult
    {
        return $this->makeClient(new MockHandler([$response]))->reconnect(9, 'https://app.test/zapmizer/reconnected', 'state-1');
    }

    protected function sentBody(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    public function testR1OnlineFromTheContract()
    {
        $body = '{"status":"online"}';
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 200, $body);

        $result = $this->reconnectWith(new Response(200, ['Content-Type' => 'application/json'], $body));

        $this->assertTrue($result->isOnline());
        $this->assertNull($result->url);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://localhost/api/bot-instances/9/reconnect', (string) $request->getUri());
        $this->assertSame('Bearer team-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('2025-06-27', $request->getHeaderLine('api-version'));
        $this->assertSame(['redirect_uri' => 'https://app.test/zapmizer/reconnected', 'state' => 'state-1'], $this->sentBody());
    }

    public function testR2StartingFromTheContract()
    {
        $body = '{"status":"starting","links":{"connection":"https://app.zapmizer.com/api/bot-instances/9/connection"}}';
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 202, $body);

        $result = $this->reconnectWith(new Response(202, ['Content-Type' => 'application/json'], $body));

        $this->assertTrue($result->isStarting());
        $this->assertNull($result->url);
    }

    public function testR3NeedsClientFromTheContract()
    {
        $body = json_encode([
            'error' => 'needs_reconnect',
            'message' => self::NEEDS_RECONNECT_MESSAGE,
            'url' => 'https://app.zapmizer.com/connect/reconnect/9?signature=abc',
            'expires_at' => '2026-10-07T15:00:00Z',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 409, $body);

        $result = $this->reconnectWith(new Response(409, ['Content-Type' => 'application/json'], $body));

        $this->assertTrue($result->needsClient());
        $this->assertSame('https://app.zapmizer.com/connect/reconnect/9?signature=abc', $result->url);
        $this->assertSame('2026-10-07T15:00:00+00:00', $result->expiresAt->toIso8601String());
    }

    public function testR4NeedsClientWithoutExpiryLogsNothing()
    {
        $body = json_encode([
            'error' => 'needs_reconnect',
            'message' => self::NEEDS_RECONNECT_MESSAGE,
            'url' => 'https://app.zapmizer.com/connect/reconnect/9',
            'expires_at' => null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 409, $body);
        Log::spy();

        $result = $this->reconnectWith(new Response(409, ['Content-Type' => 'application/json'], $body));

        $this->assertTrue($result->needsClient());
        $this->assertNull($result->expiresAt);
        Log::shouldNotHaveReceived('warning');
    }

    /** @dataProvider needsReconnectWithoutASafeUrlOutsideTheContract */
    #[DataProvider('needsReconnectWithoutASafeUrlOutsideTheContract')]
    public function testR5NeedsReconnectWithoutASafeUrlIsAnUnexpectedResponse(array $body)
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('invalid reconnect url');

        $this->reconnectWith(new Response(409, ['Content-Type' => 'application/json'], json_encode(['error' => 'needs_reconnect', 'message' => self::NEEDS_RECONNECT_MESSAGE, 'expires_at' => null] + $body)));
    }

    public static function needsReconnectWithoutASafeUrlOutsideTheContract(): array
    {
        return [
            'R5 absent' => [[]],
            'R5 empty' => [['url' => '']],
            'R5 spaces' => [['url' => '  ']],
            'R5 array' => [['url' => ['https://app.zapmizer.com/r/9']]],
            'R5 javascript' => [['url' => 'javascript:alert(1)']],
        ];
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testRefusalFromTheContract(int $status, string $body, array $headers, string $class, ?string $error)
    {
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', $status, $body, $headers);

        $exception = $this->assertRefusal(new Response($status, ['Content-Type' => 'application/json'] + $headers, $body), $class);

        $this->assertSame($error, $exception->error());
    }

    public static function refusalsFromTheContract(): array
    {
        return [
            'R7 plan limit' => [402, '{"error":"plan_limit","message":"Limite de números online do plano."}', [], ZapmizerApiException::class, ErrorCode::PLAN_LIMIT],
            'R7 subscription required' => [402, '{"error":"subscription_required"}', [], ZapmizerApiException::class, ErrorCode::SUBSCRIPTION_REQUIRED],
            'R8 403 without error' => [403, '{"message":"Só uma conexão de parceiro religa um número."}', [], ZapmizerApiException::class, null],
            'R8 403 missing ability' => [403, '{"error":"missing_ability","message":"Missing ability."}', [], ZapmizerApiException::class, ErrorCode::MISSING_ABILITY],
            'R9 404' => [404, '{"message":"No query results for model."}', [], InstanceGoneException::class, null],
            'R10 422' => [422, '{"message":"The redirect uri is not allowed.","errors":{"redirect_uri":["The redirect uri is not allowed."]}}', [], ZapmizerApiException::class, null],
            'R11 429' => [429, '{"message":"Too Many Attempts."}', ['Retry-After' => '42'], ZapmizerRateLimitedException::class, null],
            'R12 401' => [401, '{"message":"Unauthenticated."}', [], ZapmizerUnauthorizedException::class, null],
        ];
    }

    /** @dataProvider refusalsOutsideTheContract */
    #[DataProvider('refusalsOutsideTheContract')]
    public function testRefusalOutsideTheContract(Response $response, string $class, ?string $error)
    {
        $exception = $this->assertRefusal($response, $class);

        $this->assertSame($error, $exception->error());
    }

    public static function refusalsOutsideTheContract(): array
    {
        $json = ['Content-Type' => 'application/json'];

        return [
            'R6 409 bot offline' => [new Response(409, $json, '{"error":"bot_offline","message":"Offline."}'), ZapmizerApiException::class, ErrorCode::BOT_OFFLINE],
            'R6 409 without error' => [new Response(409, $json, '{"message":"Conflict."}'), ZapmizerApiException::class, null],
            'R6 409 html' => [new Response(409, ['Content-Type' => 'text/html'], '<html>Conflict</html>'), ZapmizerApiException::class, null],
            'R7 402 with a number' => [new Response(402, $json, '{"error":402}'), ZapmizerApiException::class, null],
            'R18 400' => [new Response(400, $json, '{"message":"Bad request."}'), ZapmizerApiException::class, null],
            'R18 410' => [new Response(410, $json, '{"message":"Gone."}'), ZapmizerApiException::class, null],
            '401 html' => [new Response(401, ['Content-Type' => 'text/html'], '<html>Login</html>'), ZapmizerUnauthorizedException::class, null],
        ];
    }

    protected function assertRefusal(Response $response, string $class): ZapmizerApiException
    {
        try {
            $this->reconnectWith($response);
        } catch (ZapmizerApiException $exception) {
            $this->assertSame($class, get_class($exception));
            $this->assertSame($response->getStatusCode(), $exception->status());

            return $exception;
        }

        $this->fail("Expected {$class}.");
    }

    public function testR9TheGoneInstanceIsNamed()
    {
        $exception = $this->assertRefusal(new Response(404, ['Content-Type' => 'application/json'], '{"message":"No query results for model."}'), InstanceGoneException::class);

        $this->assertSame('Instance 9 is gone on Zapmizer.', $exception->getMessage());
    }

    public function testR10TheRefusedRedirectCarriesTheFieldError()
    {
        $exception = $this->assertRefusal(new Response(422, ['Content-Type' => 'application/json'], '{"message":"Invalid.","errors":{"redirect_uri":["The redirect uri is not allowed."]}}'), ZapmizerApiException::class);

        $this->assertSame(['The redirect uri is not allowed.'], $exception->errors()['redirect_uri']);
    }

    public function testR11TheRateLimitCarriesTheRetryAfter()
    {
        $exception = $this->assertRefusal(new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '42'], '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class);

        $this->assertSame(42, $exception->retryAfter());
    }

    public function testR13AServerErrorOrANetworkFailureIsUnavailable()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(500, [], 'boom'),
            new ConnectException('timed out', new Request('POST', 'bot-instances/9/reconnect')),
        ]));

        foreach ([1, 2] as $attempt) {
            try {
                $client->reconnect(9, 'https://app.test/cb', 'state-1');
                $this->fail('Expected ZapmizerUnavailableException.');
            } catch (ZapmizerUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @dataProvider invalidStates */
    #[DataProvider('invalidStates')]
    public function testR14AStateTheApiWouldDropFailsBeforeAnyRequest(string $state)
    {
        $transport = new RecordingTransport();
        $client = new InstanceClient('team-token', $transport, 'http://localhost/api');

        try {
            $client->reconnect(9, 'https://app.test/cb', $state);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('state', $exception->getMessage());
        }

        $this->assertSame([], $transport->calls);
    }

    public static function invalidStates(): array
    {
        return ['R14 empty' => [''], 'R14 zero' => ['0'], 'R14 leading space' => [' a']];
    }

    public function testR15WithoutStateAndExpiresInTheBodyHasOnlyTheRedirect()
    {
        $client = $this->makeClient(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"status":"online"}')]));

        $client->reconnect(9, 'https://app.test/cb');

        $this->assertSame(['redirect_uri' => 'https://app.test/cb'], $this->sentBody());
    }

    public function testWithoutStateTheExpiresInStillGoes()
    {
        $client = $this->makeClient(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"status":"online"}')]));

        $client->reconnect(9, 'https://app.test/cb', expiresIn: 3600);

        $this->assertSame(['redirect_uri' => 'https://app.test/cb', 'expires_in' => 3600], $this->sentBody());
    }

    /** @dataProvider expiresIn */
    #[DataProvider('expiresIn')]
    public function testR16ExpiresInIsKeptWithinWhatZapmizerAllows(int $requested, int $sent)
    {
        $client = $this->makeClient(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"status":"online"}')]));

        $client->reconnect(9, 'https://app.test/cb', 'state-1', $requested);

        $this->assertSame(['redirect_uri' => 'https://app.test/cb', 'state' => 'state-1', 'expires_in' => $sent], $this->sentBody());
    }

    public static function expiresIn(): array
    {
        return ['R16 below the floor' => [100, 900], 'R16 above the ceiling' => [100000, 86400], 'R16 within' => [3600, 3600]];
    }

    /** @dataProvider answersThatAreNotAResult */
    #[DataProvider('answersThatAreNotAResult')]
    public function testR17AnAnswerThatIsNotAResultIsAnUnexpectedResponse(Response $response)
    {
        try {
            $this->reconnectWith($response);
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertNotInstanceOf(ZapmizerApiException::class, $exception);
            $this->assertStringContainsString('unexpected response', $exception->getMessage());
        }
    }

    public static function answersThatAreNotAResult(): array
    {
        $json = ['Content-Type' => 'application/json'];

        return [
            'R17 201' => [new Response(201, $json, '{"status":"online"}')],
            'R17 204' => [new Response(204)],
            'R17 302' => [new Response(302, ['Location' => 'http://localhost/login'])],
            'R17 200 html' => [new Response(200, ['Content-Type' => 'text/html'], '<html></html>')],
            'R17 200 cut' => [new Response(200, $json, '{"status":"onl')],
        ];
    }

    public function testTheStateComesFromTheHttpStatusNotFromTheBody()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"starting"}'),
            new Response(202, ['Content-Type' => 'application/json'], '[]'),
        ]));

        $this->assertTrue($client->reconnect(9, 'https://app.test/cb')->isOnline());
        $this->assertTrue($client->reconnect(9, 'https://app.test/cb')->isStarting());
    }

    public function testANonSeekableNeedsReconnectBodyIsReadOnce()
    {
        $body = json_encode(['error' => 'needs_reconnect', 'message' => self::NEEDS_RECONNECT_MESSAGE, 'url' => 'https://app.zapmizer.com/r/9', 'expires_at' => null]);

        $result = $this->reconnectWith(new Response(409, ['Content-Type' => 'application/json'], new NoSeekStream(Utils::streamFor($body))));

        $this->assertSame('https://app.zapmizer.com/r/9', $result->url);
    }
}
