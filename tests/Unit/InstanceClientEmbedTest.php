<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\EmbedSession;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InstanceClientEmbedTest extends TestCase
{
    use AssertsContract;

    private const CONVERSATION = '{"url":"https://app.parlichat.com/embed/start/Zr8kQ2","expires_at":"2026-09-26T14:01:00+00:00"}';

    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): InstanceClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new InstanceClient('team-token', new GuzzleTransport(new HttpClient(['handler' => $stack])), 'http://localhost/api', '2025-06-27');
    }

    protected function created(string $body = self::CONVERSATION): InstanceClient
    {
        return $this->makeClient(new MockHandler([new Response(201, ['Content-Type' => 'application/json'], $body)]));
    }

    protected function sentBody(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    public function testE1TheConversationAnswerOfTheContract()
    {
        $this->assertMatchesContract('POST', '/embed/sessions', 201, self::CONVERSATION);

        $session = $this->created()->conversationSession('5521988887777', 'https://app.test');

        $this->assertInstanceOf(EmbedSession::class, $session);
        $this->assertSame('https://app.parlichat.com/embed/start/Zr8kQ2', $session->url);
        $this->assertSame('https://app.parlichat.com', $session->origin);
        $this->assertSame('2026-09-26T14:01:00+00:00', $session->expiresAt->toIso8601String());
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://localhost/api/embed/sessions', (string) $request->getUri());
        $this->assertSame('Bearer team-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('2025-06-27', $request->getHeaderLine('api-version'));
        $this->assertSame(['component' => 'conversation', 'phone' => '5521988887777', 'parent_origin' => 'https://app.test'], $this->sentBody());
    }

    public function testE9TheNullsLeaveTheAppearance()
    {
        $this->created()->conversationSession('5521988887777', 'https://app.test', ['theme' => 'dark', 'radius' => null]);

        $this->assertSame(['theme' => 'dark'], $this->sentBody()['appearance']);
    }

    public function testAZeroOrFalseStaysInTheAppearance()
    {
        $this->created()->conversationSession('5521988887777', 'https://app.test', ['radius' => 0, 'theme' => 'light', 'color_primary' => '#2E6BFF', 'font_family' => 'Inter', 'other' => false]);

        $this->assertSame(['radius' => 0, 'theme' => 'light', 'color_primary' => '#2E6BFF', 'font_family' => 'Inter', 'other' => false], $this->sentBody()['appearance']);
    }

    /** @dataProvider emptyAppearances */
    #[DataProvider('emptyAppearances')]
    public function testE10AnEmptyAppearanceIsNotSent(array $appearance)
    {
        $this->created()->conversationSession('5521988887777', 'https://app.test', $appearance);

        $this->assertArrayNotHasKey('appearance', $this->sentBody());
    }

    public static function emptyAppearances(): array
    {
        return ['E10 empty' => [[]], 'E10 only nulls' => [['theme' => null, 'radius' => null]]];
    }

    /** @dataProvider users */
    #[DataProvider('users')]
    public function testE11TheUserGoesWithWhatIsFilled(?string $userId, ?string $userName, ?array $user)
    {
        $this->created()->conversationSession('5521988887777', 'https://app.test', [], $userId, $userName);

        $this->assertSame($user, $this->sentBody()['user'] ?? null);
    }

    public static function users(): array
    {
        return [
            'E11 both null' => [null, null, null],
            'E11 both blank' => ['', '   ', null],
            'E11 only the id' => ['u-1', null, ['id' => 'u-1']],
            'E11 only the name' => [null, 'Ana', ['name' => 'Ana']],
            'E11 blank id' => [' ', 'Ana', ['name' => 'Ana']],
            'both' => ['u-1', 'Ana', ['id' => 'u-1', 'name' => 'Ana']],
            'zero as the id' => ['0', null, ['id' => '0']],
        ];
    }

    public function testE12TheInboxSendsNoPhoneAndNoAppearanceWhenNoneIsGiven()
    {
        $body = '{"url":"https://app.parlichat.com/embed-inbox/start/Zr8kQ2","expires_at":"2026-09-26T14:01:00+00:00","resume_url":"https://app.parlichat.com/chats?embed_inbox=9b2f6c1e-4d7a-4f0e-9a51-2c8e7d3b6a10","resume_until":"2026-09-26T16:01:00+00:00"}';
        $this->assertMatchesContract('POST', '/embed/sessions', 201, $body);

        $session = $this->created($body)->inboxSession('https://app.test', [], 'u-1', 'Ana');

        $this->assertSame('https://app.parlichat.com/chats?embed_inbox=9b2f6c1e-4d7a-4f0e-9a51-2c8e7d3b6a10', $session->resumeUrl);
        $this->assertSame('2026-09-26T16:01:00+00:00', $session->resumeUntil->toIso8601String());
        $this->assertSame(['component' => 'inbox', 'parent_origin' => 'https://app.test', 'user' => ['id' => 'u-1', 'name' => 'Ana']], $this->sentBody());
    }

    public function testTheInboxWithoutUserSendsOnlyTheOrigin()
    {
        $this->created()->inboxSession('https://app.test');

        $this->assertSame(['component' => 'inbox', 'parent_origin' => 'https://app.test'], $this->sentBody());
    }

    public function testTheInboxSendsItsAppearanceWithoutNulls()
    {
        $this->created()->inboxSession('https://app.test', ['theme' => 'dark', 'color_accent' => '#7FA6FF', 'dark_background' => '#141a24', 'radius' => null]);

        $this->assertSame(
            ['component' => 'inbox', 'parent_origin' => 'https://app.test', 'appearance' => ['theme' => 'dark', 'color_accent' => '#7FA6FF', 'dark_background' => '#141a24']],
            $this->sentBody()
        );
    }

    public function testTheInboxWithAnEmptyAppearanceSendsNone()
    {
        $this->created()->inboxSession('https://app.test', ['theme' => null], 'u-1');

        $this->assertSame(['component' => 'inbox', 'parent_origin' => 'https://app.test', 'user' => ['id' => 'u-1']], $this->sentBody());
    }

    public function testTheConversationPassesTheColorAccentThrough()
    {
        $this->created()->conversationSession('5521988887777', 'https://app.test', ['color_accent' => '#7FA6FF']);

        $this->assertSame(['color_accent' => '#7FA6FF'], $this->sentBody()['appearance']);
    }

    public function testThePhoneAndTheOriginGoAsTheyCame()
    {
        $this->created()->conversationSession('abc', 'not an origin');

        $this->assertSame(['component' => 'conversation', 'phone' => 'abc', 'parent_origin' => 'not an origin'], $this->sentBody());
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testRefusalFromTheContract(int $status, string $body, string $class, ?string $error, array $errors)
    {
        $this->assertMatchesContract('POST', '/embed/sessions', $status, $body);

        $exception = $this->assertRefusal(new Response($status, ['Content-Type' => 'application/json'], $body), $class);

        $this->assertSame($error, $exception->error());
        $this->assertSame($errors, $exception->errors());
    }

    public static function refusalsFromTheContract(): array
    {
        return [
            'E13 402' => [402, '{"error":"subscription_required","message":"Sem assinatura."}', ZapmizerApiException::class, ErrorCode::SUBSCRIPTION_REQUIRED, []],
            'E13 403 not a partner connection' => [403, '{"error":"not_a_partner_connection"}', ZapmizerApiException::class, ErrorCode::NOT_A_PARTNER_CONNECTION, []],
            'E13 403 origin not allowed' => [403, '{"error":"origin_not_allowed","message":"Origem não liberada."}', ZapmizerApiException::class, ErrorCode::ORIGIN_NOT_ALLOWED, []],
            'E13 403 missing ability' => [403, '{"error":"missing_ability"}', ZapmizerApiException::class, ErrorCode::MISSING_ABILITY, []],
            'E13 422 with error' => [422, '{"error":"connection_without_number","message":"Sem número."}', ZapmizerApiException::class, ErrorCode::CONNECTION_WITHOUT_NUMBER, []],
            'E13 422 with errors' => [422, '{"message":"The phone field format is invalid.","errors":{"phone":["The phone field format is invalid."]}}', ZapmizerApiException::class, null, ['phone' => ['The phone field format is invalid.']]],
            'E13 422 only message' => [422, '{"message":"Invalid."}', ZapmizerApiException::class, null, []],
            'E14 401' => [401, '{"message":"Unauthenticated."}', ZapmizerUnauthorizedException::class, null, []],
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
            'E13 402 without error' => [new Response(402, $json, '{"message":"Payment required."}'), ZapmizerApiException::class, null],
            'E13 403 without error' => [new Response(403, $json, '{"message":"Forbidden."}'), ZapmizerApiException::class, null],
            'E14 429' => [new Response(429, $json + ['Retry-After' => '30'], '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class, null],
            'E14 404' => [new Response(404, $json, '{"message":"Not Found"}'), ZapmizerApiException::class, null],
            '409 with error' => [new Response(409, $json, '{"error":"bot_offline"}'), ZapmizerApiException::class, ErrorCode::BOT_OFFLINE],
        ];
    }

    protected function assertRefusal(Response $response, string $class): ZapmizerApiException
    {
        try {
            $this->makeClient(new MockHandler([$response]))->conversationSession('5521988887777', 'https://app.test');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame($class, get_class($exception));
            $this->assertSame($response->getStatusCode(), $exception->status());

            return $exception;
        }

        $this->fail("Expected {$class}.");
    }

    public function testE14AServerErrorOrANetworkFailureIsUnavailable()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(500, [], 'boom'),
            new ConnectException('timed out', new Request('POST', 'embed/sessions')),
        ]));

        foreach ([1, 2] as $attempt) {
            try {
                $client->inboxSession('https://app.test');
                $this->fail('Expected ZapmizerUnavailableException.');
            } catch (ZapmizerUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @dataProvider answersThatAreNotASession */
    #[DataProvider('answersThatAreNotASession')]
    public function testE15AnAnswerThatIsNotASessionIsAnUnexpectedResponse(Response $response, string $reason)
    {
        try {
            $this->makeClient(new MockHandler([$response]))->conversationSession('5521988887777', 'https://app.test');
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertNotInstanceOf(ZapmizerApiException::class, $exception);
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
    }

    public static function answersThatAreNotASession(): array
    {
        $json = ['Content-Type' => 'application/json'];

        return [
            'E15 200 with url' => [new Response(200, $json, self::CONVERSATION), 'HTTP 200'],
            'E15 201 cut' => [new Response(201, $json, '{"url":"https://app.parl'), 'not valid JSON'],
            'E15 201 with a list' => [new Response(201, $json, '[]'), 'invalid embed url'],
            'E15 302' => [new Response(302, ['Location' => 'http://localhost/login']), '302'],
            '201 without url' => [new Response(201, $json, '{"expires_at":"2026-09-26T14:01:00+00:00"}'), 'invalid embed url'],
            '201 with an unsafe url' => [new Response(201, $json, '{"url":"javascript:alert(1)","expires_at":"2026-09-26T14:01:00+00:00"}'), 'invalid embed url'],
            '201 in html' => [new Response(201, ['Content-Type' => 'text/html'], '<html></html>'), 'text/html'],
        ];
    }
}
