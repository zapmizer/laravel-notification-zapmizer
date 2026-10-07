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
use NotificationChannels\Zapmizer\Connect\ConnectSession;
use NotificationChannels\Zapmizer\Connect\ConnectToken;
use NotificationChannels\Zapmizer\Connect\PartnerCheckout;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\PartnerSubscription;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PartnerClientTest extends TestCase
{
    use AssertsContract;

    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): PartnerClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new PartnerClient('partner-id', 'partner-secret', new GuzzleTransport(new HttpClient(['handler' => $stack])), 'http://localhost/api');
    }

    public function testCreateSessionSendsPartnerKeyAndReturnsPopupUrl()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode(['url' => 'http://localhost/connect/1?signature=abc', 'expires_at' => '2026-09-07T01:00:00.000000Z'])),
        ]));

        $session = $client->createSession('http://app.test/zapmizer/connect/callback', 'state-123', 'http://app.test/zapmizer/webhook');

        $this->assertInstanceOf(ConnectSession::class, $session);
        $this->assertEquals('http://localhost/connect/1?signature=abc', $session->url);
        $this->assertSame('2026-09-07 01:00:00.000000+00:00', $session->expiresAt->format('Y-m-d H:i:s.uP'));
        $this->assertSame(['url' => $session->url, 'expires_at' => '2026-09-07T01:00:00+00:00'], $session->jsonSerialize());

        $request = $this->history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://localhost/api/connect/sessions', (string) $request->getUri());
        $this->assertEquals('partner-id|partner-secret', $request->getHeaderLine('X-Partner-Key'));
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertEquals(
            ['redirect_uri' => 'http://app.test/zapmizer/connect/callback', 'state' => 'state-123', 'webhook_url' => 'http://app.test/zapmizer/webhook'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testCreateSessionWithoutAWebhookSendsNone()
    {
        $client = $this->makeClient(new MockHandler([new Response(201, [], json_encode(['url' => 'http://localhost/connect/1']))]));

        $client->createSession('http://app.test/cb', 'state');

        $this->assertEquals(['redirect_uri' => 'http://app.test/cb', 'state' => 'state'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    /** @dataProvider expiresIn */
    #[DataProvider('expiresIn')]
    public function testP5CreateSessionKeepsExpiresInWithinWhatZapmizerAllows(int $requested, int $sent)
    {
        $client = $this->makeClient(new MockHandler([new Response(201, [], json_encode(['url' => 'http://localhost/connect/1']))]));

        $client->createSession('http://app.test/cb', 'state', null, $requested);

        $this->assertSame($sent, json_decode((string) $this->history[0]['request']->getBody(), true)['expires_in']);
        $this->assertGreaterThanOrEqual(PartnerClient::MIN_EXPIRES_IN, $sent);
        $this->assertLessThanOrEqual(PartnerClient::MAX_EXPIRES_IN, $sent);
    }

    public static function expiresIn(): array
    {
        return [
            'P5 far below the floor' => [100, 900],
            'below the floor' => [300, 900],
            'at the floor' => [900, 900],
            'P5 within' => [3600, 3600],
            'at the ceiling' => [86400, 86400],
            'P5 above the ceiling' => [100000, 86400],
        ];
    }

    public function testP1CreateSessionSendsTheExternalId()
    {
        $body = '{"url":"http://localhost/connect/1?signature=abc","expires_at":"2026-09-07T01:00:00Z"}';
        $this->assertMatchesContract('POST', '/connect/sessions', 201, $body);
        $client = $this->makeClient(new MockHandler([new Response(201, ['Content-Type' => 'application/json'], $body)]));

        $session = $client->createSession(redirectUri: 'http://app.test/cb', state: 'state 123', externalId: '42');

        $this->assertSame('http://localhost/connect/1?signature=abc', $session->url);
        $this->assertSame('2026-09-07T01:00:00+00:00', $session->expiresAt->toIso8601String());
        $this->assertSame(
            ['redirect_uri' => 'http://app.test/cb', 'state' => 'state 123', 'external_id' => '42'],
            json_decode((string) $this->history[0]['request']->getBody(), true),
        );
    }

    /** @dataProvider validExternalIds */
    #[DataProvider('validExternalIds')]
    public function testAValidExternalIdGoesAsItIs(string $externalId)
    {
        $client = $this->makeClient(new MockHandler([new Response(201, [], json_encode(['url' => 'http://localhost/connect/1']))]));

        $client->createSession('http://app.test/cb', 'state', externalId: $externalId);

        $this->assertSame($externalId, json_decode((string) $this->history[0]['request']->getBody(), true)['external_id']);
    }

    public static function validExternalIds(): array
    {
        return [
            'one digit' => ['1'],
            'all the characters' => ['Team_42.a-B'],
            '191 characters' => [str_repeat('a', 191)],
            'three dots' => ['...'],
            'dot inside' => ['a.b'],
        ];
    }

    /** @dataProvider invalidExternalIds */
    #[DataProvider('invalidExternalIds')]
    public function testP2AnInvalidExternalIdFailsBeforeAnyRequest(string $externalId)
    {
        $transport = new RecordingTransport();
        $client = new PartnerClient('partner-id', 'partner-secret', $transport, 'http://localhost/api');

        try {
            $client->createSession('http://app.test/cb', 'state', externalId: $externalId);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('external id', $exception->getMessage());
        }

        $this->assertSame([], $transport->calls);
    }

    public static function invalidExternalIds(): array
    {
        return [
            'space' => ['a b'],
            'empty' => [''],
            '192 characters' => [str_repeat('a', 192)],
            'accent' => ['ção'],
            'trailing newline' => ["abc\n"],
            'dot' => ['.'],
            'two dots' => ['..'],
            'slash' => ['a/b'],
        ];
    }

    /** @dataProvider invalidStates */
    #[DataProvider('invalidStates')]
    public function testP3AStateTheApiWouldDropFailsBeforeAnyRequest(string $state)
    {
        $transport = new RecordingTransport();
        $client = new PartnerClient('partner-id', 'partner-secret', $transport, 'http://localhost/api');

        try {
            $client->createSession('http://app.test/cb', $state);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('state', $exception->getMessage());
        }

        $this->assertSame([], $transport->calls);
    }

    public static function invalidStates(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'only spaces' => ['   '],
            'leading space' => [' abc'],
            'trailing newline' => ["abc\n"],
            'leading tab' => ["\tabc"],
            'trailing zero-width space' => ["abc\u{200B}"],
        ];
    }

    public function testP4WithoutStateTheBodyHasNone()
    {
        $client = $this->makeClient(new MockHandler([new Response(201, [], json_encode(['url' => 'http://localhost/connect/1']))]));

        $client->createSession('http://app.test/cb');

        $this->assertSame(['redirect_uri' => 'http://app.test/cb'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testEmptyUrisGoInTheBodyForTheApiToRefuse()
    {
        $client = $this->makeClient(new MockHandler([new Response(201, [], json_encode(['url' => 'http://localhost/connect/1']))]));

        $client->createSession('', 'state', '');

        $this->assertSame(['redirect_uri' => '', 'state' => 'state', 'webhook_url' => ''], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testAnUnreadableExpiryOfTheSessionIsLoggedWithTheExternalId()
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response(201, [], json_encode(['url' => 'http://localhost/connect/1', 'expires_at' => 'tomorrow']))]));

        $this->assertNull($client->createSession('http://app.test/cb', 'state', externalId: '42')->expiresAt);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: unreadable date.'
            && $context === ['field' => 'expires_at', 'value' => 'tomorrow', 'external_id' => '42']);
    }

    public function testExchangeCodeReturnsTheTeamTokenWithThePairing()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode([
                'token' => '1|sanctum', 'user_id' => 3, 'team_id' => 7, 'team_name' => 'Acme',
                'phone_number' => '5511999990000', 'bot_instance_id' => 42, 'webhook_id' => 9, 'webhook_secret' => 'whsec_x',
            ])),
        ]));

        $token = $client->exchangeCode('12.secret');

        $this->assertInstanceOf(ConnectToken::class, $token);
        $this->assertEquals('1|sanctum', $token->token);
        $this->assertSame(3, $token->userId);
        $this->assertSame(7, $token->teamId);
        $this->assertEquals('Acme', $token->teamName);
        $this->assertEquals('5511999990000', $token->phoneNumber);
        $this->assertEquals(42, $token->botInstanceId);
        $this->assertEquals(9, $token->webhookId);
        $this->assertEquals('whsec_x', $token->webhookSecret);
        $this->assertFalse($token->needsWebhookSecret());
        $this->assertEquals(['code' => '12.secret'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testExchangeCodeTellsAReusedWebhookApart()
    {
        $client = $this->makeClient(new MockHandler([
            // Webhook reused on the team: id comes, secret does not.
            new Response(200, [], json_encode(['token' => '1|sanctum', 'user_id' => 3, 'team_id' => 7, 'team_name' => 'Acme', 'phone_number' => '5511999990000', 'bot_instance_id' => 42, 'webhook_id' => 9, 'webhook_secret' => null])),
            // No webhook_url was sent: nothing to rotate.
            new Response(200, [], json_encode(['token' => '1|sanctum', 'user_id' => 3, 'team_id' => 7, 'team_name' => 'Acme', 'phone_number' => '5511999990000', 'bot_instance_id' => 42, 'webhook_id' => null, 'webhook_secret' => null])),
        ]));

        $reused = $client->exchangeCode('c');
        $this->assertEquals(9, $reused->webhookId);
        $this->assertNull($reused->webhookSecret);
        $this->assertTrue($reused->needsWebhookSecret());

        $none = $client->exchangeCode('c');
        $this->assertNull($none->webhookId);
        $this->assertFalse($none->needsWebhookSecret());
    }

    public function testExchangeCodeReturnsNullForUnknownCode()
    {
        $client = $this->makeClient(new MockHandler([new Response(404, [], '{"message":"Record not found."}')]));

        $this->assertNull($client->exchangeCode('bad'));
    }

    /** @dataProvider credentialFailures */
    #[DataProvider('credentialFailures')]
    public function testRefusedPartnerKeyBecomesTypedExceptionAndIsLogged(int $status)
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response($status, [], '{"message":"Unauthenticated."}')]));

        try {
            $client->createSession('http://app.test/cb', 'state');
            $this->fail('Expected PartnerCredentialsException.');
        } catch (PartnerCredentialsException $exception) {
            $this->assertSame($status, $exception->status());
            $this->assertSame('Zapmizer refused the partner credentials.', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['status'] === $status);
    }

    public static function credentialFailures(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    protected function callEndpoint(PartnerClient $client, string $endpoint): mixed
    {
        return $endpoint === '/connect/sessions'
            ? $client->createSession('http://app.test/cb', 'state')
            : $client->exchangeCode('12.secret');
    }

    public function testM1ValidationErrorIsAnApiExceptionWithTheFieldErrorsAndIsLogged()
    {
        $body = '{"message":"The redirect uri field is required.","errors":{"redirect_uri":["The redirect uri field is required."]}}';
        $this->assertMatchesContract('POST', '/connect/sessions', 422, $body);
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response(422, ['Content-Type' => 'application/json'], $body)]));

        try {
            $client->createSession('http://app.test/cb', 'state');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(ZapmizerApiException::class, get_class($exception));
            $this->assertSame(422, $exception->status());
            $this->assertNull($exception->error());
            $this->assertSame(['redirect_uri' => ['The redirect uri field is required.']], $exception->errors());
            $this->assertSame('The redirect uri field is required. The redirect uri field is required.', $exception->reason());
            $this->assertSame('Zapmizer refused the request (HTTP 422): The redirect uri field is required. The redirect uri field is required.', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: partner call failed.'
            && $context === ['endpoint' => 'connect/sessions', 'status' => 422, 'body' => $body]);
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testRefusalFromTheContract(string $endpoint, int $status, string $body, array $headers, string $class, ?int $retryAfter)
    {
        $this->assertMatchesContract('POST', $endpoint, $status, $body, $headers);
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response($status, ['Content-Type' => 'application/json'] + $headers, $body)]));

        try {
            $this->callEndpoint($client, $endpoint);
            $this->fail("Expected {$class}.");
        } catch (ZapmizerApiException $exception) {
            $this->assertSame($class, get_class($exception));
            $this->assertSame($status, $exception->status());

            if ($exception instanceof ZapmizerRateLimitedException) {
                $this->assertSame($retryAfter, $exception->retryAfter());
            }
        }
    }

    public static function refusalsFromTheContract(): array
    {
        return [
            'M3 sessions 429' => ['/connect/sessions', 429, '{"message":"Too Many Attempts."}', ['Retry-After' => '12'], ZapmizerRateLimitedException::class, 12],
            'token 422' => ['/connect/token', 422, '{"message":"The code field is required.","errors":{"code":["The code field is required."]}}', [], ZapmizerApiException::class, null],
            'token 429' => ['/connect/token', 429, '{"message":"Too Many Attempts."}', ['Retry-After' => '30'], ZapmizerRateLimitedException::class, 30],
        ];
    }

    /** @dataProvider refusalsOutsideTheContract */
    #[DataProvider('refusalsOutsideTheContract')]
    public function testRefusalOutsideTheContract(string $endpoint, Response $response, string $class, ?int $retryAfter)
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([$response]));

        try {
            $this->callEndpoint($client, $endpoint);
            $this->fail("Expected {$class}.");
        } catch (ZapmizerApiException $exception) {
            $this->assertSame($class, get_class($exception));
            $this->assertSame($response->getStatusCode(), $exception->status());

            if ($exception instanceof ZapmizerRateLimitedException) {
                $this->assertSame($retryAfter, $exception->retryAfter());
            }
        }
    }

    public static function refusalsOutsideTheContract(): array
    {
        $tooMany = '{"message":"Too Many Attempts."}';

        return [
            'sessions 404' => ['/connect/sessions', new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not Found"}'), ZapmizerApiException::class, null],
            'sessions 409' => ['/connect/sessions', new Response(409, ['Content-Type' => 'application/json'], '{"error":"something_new"}'), ZapmizerApiException::class, null],
            'token 409' => ['/connect/token', new Response(409, ['Content-Type' => 'application/json'], '{"message":"Conflict."}'), ZapmizerApiException::class, null],
            'M4 429 without Retry-After' => ['/connect/sessions', new Response(429, [], $tooMany), ZapmizerRateLimitedException::class, null],
            'M4 429 with an HTTP date' => ['/connect/sessions', new Response(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], $tooMany), ZapmizerRateLimitedException::class, null],
            'M4 429 with -1' => ['/connect/sessions', new Response(429, ['Retry-After' => '-1'], $tooMany), ZapmizerRateLimitedException::class, null],
            'M4 429 with text' => ['/connect/sessions', new Response(429, ['Retry-After' => 'abc'], $tooMany), ZapmizerRateLimitedException::class, null],
        ];
    }

    /** @dataProvider businessAnswers */
    #[DataProvider('businessAnswers')]
    public function testNotFoundAndConflictAreNotLogged(Response $response)
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([$response]));

        try {
            $client->createSession('http://app.test/cb', 'state');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(ZapmizerApiException::class, get_class($exception));
            $this->assertSame($response->getStatusCode(), $exception->status());
        }

        Log::shouldNotHaveReceived('error');
    }

    public static function businessAnswers(): array
    {
        return [
            '404 not found' => [new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not Found"}')],
            '409 known code' => [new Response(409, ['Content-Type' => 'application/json'], '{"error":"already_subscribed"}')],
            '409 unknown code' => [new Response(409, ['Content-Type' => 'application/json'], '{"error":"something_new"}')],
        ];
    }

    /** @dataProvider otherRefusals */
    #[DataProvider('otherRefusals')]
    public function testOtherRefusalsAreStillLogged(int $status)
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response($status, ['Content-Type' => 'application/json'], '{"message":"No."}')]));

        try {
            $client->createSession('http://app.test/cb', 'state');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame($status, $exception->status());
        }

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: partner call failed.'
            && $context['status'] === $status);
    }

    public static function otherRefusals(): array
    {
        return ['400' => [400], '403' => [403], '410' => [410], '422' => [422], '429' => [429]];
    }

    public function testM48ANonSeekableBodyIsReadOnceForTheExceptionAndTheLog()
    {
        Log::spy();
        $body = '{"message":"The redirect uri field is required.","errors":{"redirect_uri":["required"]}}';
        $transport = new RecordingTransport(new Response(422, ['Content-Type' => 'application/json'], new NoSeekStream(Utils::streamFor($body))));
        $client = new PartnerClient('partner-id', 'partner-secret', $transport, 'http://localhost/api');

        try {
            $client->createSession('http://app.test/cb', 'state');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame('The redirect uri field is required. required', $exception->reason());
            $this->assertSame(['redirect_uri' => ['required']], $exception->errors());
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['body'] === $body);
    }

    public function testTheLoggedBodyIsCutAt500Characters()
    {
        Log::spy();
        $html = '<html>' . str_repeat('x', 800) . '</html>';
        $client = $this->makeClient(new MockHandler([new Response(422, ['Content-Type' => 'text/html'], $html)]));

        try {
            $client->createSession('http://app.test/cb', 'state');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(substr($html, 0, 500), $exception->reason());
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['body'] === substr($html, 0, 500));
    }

    protected function subscriptionBody(array $overrides = []): string
    {
        return json_encode(array_merge([
            'user_id' => 3, 'team_id' => 7, 'external_id' => '42', 'subscribed' => true,
            'quantity' => 2, 'trial_ends_at' => null, 'payment_incomplete' => false,
        ], $overrides));
    }

    public function testP13SubscriptionReadsTheAnswerOfTheContract()
    {
        $body = $this->subscriptionBody();
        $this->assertMatchesContract('GET', '/partner/users/{externalId}', 200, $body);
        $client = $this->makeClient(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)]));

        $subscription = $client->subscription('42');

        $this->assertInstanceOf(PartnerSubscription::class, $subscription);
        $this->assertSame(3, $subscription->userId);
        $this->assertSame(7, $subscription->teamId);
        $this->assertSame('42', $subscription->externalId);
        $this->assertTrue($subscription->subscribed);
        $this->assertSame(2, $subscription->quantity);
        $this->assertTrue($subscription->hasAccess());

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('http://localhost/api/partner/users/42', (string) $request->getUri());
        $this->assertSame('partner-id|partner-secret', $request->getHeaderLine('X-Partner-Key'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('', (string) $request->getBody());
    }

    public function testP17SubscriptionOfAnUnknownCustomerIsNull()
    {
        $body = '{"message":"Not found."}';
        $this->assertMatchesContract('GET', '/partner/users/{externalId}', 404, $body);
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response(404, ['Content-Type' => 'application/json'], $body)]));

        $this->assertNull($client->subscription('42'));

        Log::shouldNotHaveReceived('error');
    }

    public function testP18SubscriptionRateLimitFromTheContract()
    {
        $body = '{"message":"Too Many Attempts."}';
        $this->assertMatchesContract('GET', '/partner/users/{externalId}', 429, $body, ['Retry-After' => '20']);
        $client = $this->makeClient(new MockHandler([new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '20'], $body)]));

        try {
            $client->subscription('42');
            $this->fail('Expected ZapmizerRateLimitedException.');
        } catch (ZapmizerRateLimitedException $exception) {
            $this->assertSame(429, $exception->status());
            $this->assertSame(20, $exception->retryAfter());
        }
    }

    /** @dataProvider subscriptionFailuresOutsideTheContract */
    #[DataProvider('subscriptionFailuresOutsideTheContract')]
    public function testP18SubscriptionFailureOutsideTheContract(mixed $answer, string $class)
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([$answer]));

        $this->expectException($class);

        $client->subscription('42');
    }

    public static function subscriptionFailuresOutsideTheContract(): array
    {
        return [
            'P18 401' => [new Response(401, ['Content-Type' => 'application/json'], '{"message":"Unauthenticated."}'), PartnerCredentialsException::class],
            '403' => [new Response(403, ['Content-Type' => 'application/json'], '{"message":"Forbidden."}'), PartnerCredentialsException::class],
            '422' => [new Response(422, ['Content-Type' => 'application/json'], '{"message":"Invalid.","errors":{"external_id":["Invalid."]}}'), ZapmizerApiException::class],
            'P18 500' => [new Response(500, [], 'boom'), ZapmizerUnavailableException::class],
            'network' => [new ConnectException('timed out', new Request('GET', 'partner/users/42')), ZapmizerUnavailableException::class],
            'redirect' => [new Response(302, ['Location' => 'http://localhost/login'], ''), ZapmizerConnectException::class],
            'html 200' => [new Response(200, ['Content-Type' => 'text/html'], '<html></html>'), ZapmizerConnectException::class],
            'answer without ids' => [new Response(200, ['Content-Type' => 'application/json'], '{"subscribed":true}'), ZapmizerConnectException::class],
        ];
    }

    /** @dataProvider externalIdsThatWouldMoveThePath */
    #[DataProvider('externalIdsThatWouldMoveThePath')]
    public function testP19SubscriptionRefusesAnExternalIdBeforeAnyRequest(string $externalId)
    {
        $transport = new RecordingTransport();
        $client = new PartnerClient('partner-id', 'partner-secret', $transport, 'http://localhost/api');

        try {
            $client->subscription($externalId);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $transport->calls);
        }
    }

    public static function externalIdsThatWouldMoveThePath(): array
    {
        return ['slash' => ['a/b'], 'two dots' => ['..'], 'dot' => ['.'], 'query' => ['a?b'], 'empty' => ['']];
    }

    public function testP25CheckoutSendsTheStateAndReadsTheAnswerOfTheContract()
    {
        $body = '{"url":"https://checkout.test/c/1","expires_at":"2026-09-07T01:00:00Z"}';
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 200, $body);
        $client = $this->makeClient(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)]));

        $checkout = $client->checkout('42', 'http://app.test/billing/return', 'state-123');

        $this->assertInstanceOf(PartnerCheckout::class, $checkout);
        $this->assertSame('https://checkout.test/c/1', $checkout->url);
        $this->assertSame('2026-09-07T01:00:00+00:00', $checkout->expiresAt->toIso8601String());

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://localhost/api/partner/users/42/checkout', (string) $request->getUri());
        $this->assertSame('partner-id|partner-secret', $request->getHeaderLine('X-Partner-Key'));
        $this->assertSame(
            ['redirect_uri' => 'http://app.test/billing/return', 'state' => 'state-123'],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function testCheckoutWithoutStateSendsOnlyTheRedirect()
    {
        $client = $this->makeClient(new MockHandler([new Response(200, [], '{"url":"https://checkout.test/c/1","expires_at":null}')]));

        $this->assertNull($client->checkout('42', 'http://app.test/billing/return')->expiresAt);

        $this->assertSame(['redirect_uri' => 'http://app.test/billing/return'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    /** @dataProvider checkoutConflictsFromTheContract */
    #[DataProvider('checkoutConflictsFromTheContract')]
    public function testP21CheckoutConflictFromTheContractIsAnApiExceptionWithTheCode(string $code)
    {
        $body = json_encode(['error' => $code, 'message' => 'No checkout.']);
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 409, $body);
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response(409, ['Content-Type' => 'application/json'], $body)]));

        try {
            $client->checkout('42', 'http://app.test/billing/return', 'state');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(ZapmizerApiException::class, get_class($exception));
            $this->assertSame(409, $exception->status());
            $this->assertSame($code, $exception->error());
        }

        Log::shouldNotHaveReceived('error');
    }

    public static function checkoutConflictsFromTheContract(): array
    {
        return [
            'already_subscribed' => [ErrorCode::ALREADY_SUBSCRIBED],
            'payment_incomplete' => [ErrorCode::PAYMENT_INCOMPLETE],
        ];
    }

    public function testP22CheckoutConflictWithAnUnknownCodeKeepsTheCode()
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response(409, ['Content-Type' => 'application/json'], '{"error":"plan_paused"}')]));

        try {
            $client->checkout('42', 'http://app.test/billing/return');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(ZapmizerApiException::class, get_class($exception));
            $this->assertSame('plan_paused', $exception->error());
        }

        Log::shouldNotHaveReceived('error');
    }

    public function testP23CheckoutOfAnUnknownCustomerIsAnApiExceptionWithTheStatus()
    {
        $body = '{"message":"Not found."}';
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 404, $body);
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response(404, ['Content-Type' => 'application/json'], $body)]));

        try {
            $client->checkout('42', 'http://app.test/billing/return');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(ZapmizerApiException::class, get_class($exception));
            $this->assertSame(404, $exception->status());
        }

        Log::shouldNotHaveReceived('error');
    }

    public function testP24CheckoutRefusingTheRedirectCarriesTheFieldError()
    {
        $body = '{"message":"The redirect uri is not allowed.","errors":{"redirect_uri":["The redirect uri is not allowed."]}}';
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 422, $body);
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response(422, ['Content-Type' => 'application/json'], $body)]));

        try {
            $client->checkout('42', 'http://evil.test/return');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(422, $exception->status());
            $this->assertSame(['The redirect uri is not allowed.'], $exception->errors()['redirect_uri']);
        }

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['endpoint'] === 'partner/users/42/checkout'
            && $context['status'] === 422);
    }

    public function testCheckoutRateLimitFromTheContract()
    {
        $body = '{"message":"Too Many Attempts."}';
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 429, $body, ['Retry-After' => '15']);
        $client = $this->makeClient(new MockHandler([new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '15'], $body)]));

        try {
            $client->checkout('42', 'http://app.test/billing/return');
            $this->fail('Expected ZapmizerRateLimitedException.');
        } catch (ZapmizerRateLimitedException $exception) {
            $this->assertSame(15, $exception->retryAfter());
        }
    }

    /** @dataProvider checkoutFailuresOutsideTheContract */
    #[DataProvider('checkoutFailuresOutsideTheContract')]
    public function testCheckoutFailureOutsideTheContract(mixed $answer, string $class)
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([$answer]));

        $this->expectException($class);

        $client->checkout('42', 'http://app.test/billing/return');
    }

    public static function checkoutFailuresOutsideTheContract(): array
    {
        return [
            '401' => [new Response(401, ['Content-Type' => 'application/json'], '{"message":"Unauthenticated."}'), PartnerCredentialsException::class],
            '403' => [new Response(403, ['Content-Type' => 'application/json'], '{"message":"Forbidden."}'), PartnerCredentialsException::class],
            '500' => [new Response(500, [], 'boom'), ZapmizerUnavailableException::class],
            'network' => [new ConnectException('timed out', new Request('POST', 'partner/users/42/checkout')), ZapmizerUnavailableException::class],
            'redirect' => [new Response(302, ['Location' => 'http://localhost/login'], ''), ZapmizerConnectException::class],
            'html 200' => [new Response(200, ['Content-Type' => 'text/html'], '<html></html>'), ZapmizerConnectException::class],
            'answer without url' => [new Response(200, ['Content-Type' => 'application/json'], '{"expires_at":null}'), ZapmizerConnectException::class],
        ];
    }

    /** @dataProvider externalIdsThatWouldMoveThePath */
    #[DataProvider('externalIdsThatWouldMoveThePath')]
    public function testP19CheckoutRefusesAnExternalIdBeforeAnyRequest(string $externalId)
    {
        $transport = new RecordingTransport();
        $client = new PartnerClient('partner-id', 'partner-secret', $transport, 'http://localhost/api');

        try {
            $client->checkout($externalId, 'http://app.test/billing/return');
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $transport->calls);
        }
    }

    /** @dataProvider invalidStates */
    #[DataProvider('invalidStates')]
    public function testCheckoutRefusesAStateTheApiWouldDropBeforeAnyRequest(string $state)
    {
        $transport = new RecordingTransport();
        $client = new PartnerClient('partner-id', 'partner-secret', $transport, 'http://localhost/api');

        try {
            $client->checkout('42', 'http://app.test/billing/return', $state);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $transport->calls);
        }
    }

    public function testServerErrorAndNetworkFailureBecomeUnavailable()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(500, [], 'boom'),
            new ConnectException('timed out', new Request('POST', 'connect/sessions')),
        ]));

        foreach ([1, 2] as $attempt) {
            try {
                $client->createSession('http://app.test/cb', 'state');
                $this->fail('Expected ZapmizerUnavailableException.');
            } catch (ZapmizerUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMissingPartnerCredentialsThrowBeforeAnyRequest()
    {
        $client = new PartnerClient();

        $this->expectException(ZapmizerConnectException::class);

        $client->createSession('http://app.test/cb', 'state');
    }

    public function testContainerResolvesClientWithConfig()
    {
        config()->set('zapmizer.partner.id', 'cfg-id');
        config()->set('zapmizer.partner.secret', 'cfg-secret');
        config()->set('zapmizer.base_uri', 'http://zap.test/api/');

        $this->assertEquals('http://zap.test/api', app(PartnerClient::class)->getApiBaseUri());
    }

    public function testARedirectIsRefusedInsteadOfFollowed()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(302, ['Location' => 'http://localhost/login'], ''),
        ]));

        try {
            $client->exchangeCode('code');
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertStringContainsString('302', $exception->getMessage());
        }

        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }
}
