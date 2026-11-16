<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Concerns\InvalidBaseUris;
use NotificationChannels\Zapmizer\Test\Fixtures\EmbedResponseWithExpiry;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TypeError;

class EmbedSessionResponseTest extends TestCase
{
    use AssertsContract;
    use FakesZapmizerHttp;
    use InvalidBaseUris;

    private const CONVERSATION = '{"url":"https://app.parlichat.com/embed/start/Zr8kQ2","expires_at":"2026-09-26T14:01:00+00:00"}';

    private const INBOX = '{"url":"https://app.parlichat.com/embed-inbox/start/Zr8kQ2","expires_at":"2026-09-26T14:01:00+00:00","resume_url":"https://app.parlichat.com/chats?embed_inbox=9b2f6c1e-4d7a-4f0e-9a51-2c8e7d3b6a10","resume_until":"2026-09-26T16:01:00+00:00"}';

    private const INBOX_SESSION = [
        'url' => 'https://app.parlichat.com/embed-inbox/start/Zr8kQ2',
        'origin' => 'https://app.parlichat.com',
        'resume_url' => 'https://app.parlichat.com/chats?embed_inbox=9b2f6c1e-4d7a-4f0e-9a51-2c8e7d3b6a10',
        'resume_until' => '2026-09-26T16:01:00+00:00',
    ];

    protected array $history = [];

    protected array $reported = [];

    protected array $hooked = [];

    protected function client(Response|\Throwable ...$answers): InstanceClient
    {
        $this->fakeZapmizer(new MockHandler($answers));

        return new InstanceClient('team-token', new LaravelHttpTransport(), 'http://localhost/api', '2025-06-27');
    }

    protected function created(string $body): InstanceClient
    {
        return $this->client(new Response(201, ['Content-Type' => 'application/json'], $body));
    }

    protected function answer(EmbedSessionResponse $response): \Illuminate\Http\JsonResponse
    {
        return $response->toResponse(Request::create('/'));
    }

    protected function recordReports(): void
    {
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $exception) {
            $this->reported[] = $exception;
        });
    }

    private static function inbox(array $changes, array $without = []): string
    {
        $body = array_diff_key(json_decode(self::INBOX, true), array_flip($without));

        return json_encode(array_merge($body, $changes), JSON_UNESCAPED_SLASHES);
    }

    public function testS1TheConversationOfTheContract()
    {
        $this->assertMatchesContract('POST', '/embed/sessions', 201, self::CONVERSATION);
        $client = $this->created(self::CONVERSATION);

        $json = $this->answer(EmbedSessionResponse::open(fn () => $client->conversationSession('5521988887777', 'https://app.test')));

        $this->assertSame(200, $json->getStatusCode());
        $this->assertSame([
            'url' => 'https://app.parlichat.com/embed/start/Zr8kQ2',
            'origin' => 'https://app.parlichat.com',
            'resume_url' => null,
            'resume_until' => null,
        ], $json->getData(true));
    }

    public function testS2TheInboxOfTheContract()
    {
        $this->assertMatchesContract('POST', '/embed/sessions', 201, self::INBOX);
        $client = $this->created(self::INBOX);

        $json = $this->answer(EmbedSessionResponse::open(fn () => $client->inboxSession('https://app.test')));

        $this->assertSame(200, $json->getStatusCode());
        $this->assertSame(self::INBOX_SESSION, $json->getData(true));
    }

    public function testTheResumeUntilKeepsItsOffsetInAnAppOutsideUtc()
    {
        $previous = date_default_timezone_get();
        config()->set('app.timezone', 'America/Sao_Paulo');
        date_default_timezone_set('America/Sao_Paulo');

        try {
            $client = $this->created(self::INBOX);
            $json = $this->answer(EmbedSessionResponse::open(fn () => $client->inboxSession('https://app.test')));
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame('2026-09-26T16:01:00+00:00', $json->getData(true)['resume_until']);
    }

    /** @dataProvider resumeUntils */
    #[DataProvider('resumeUntils')]
    public function testS3TheResumeUntilIsWrittenWithItsOffset(string $sent, string $answered)
    {
        $client = $this->created(self::inbox(['resume_until' => $sent]));

        $json = $this->answer(EmbedSessionResponse::open(fn () => $client->inboxSession('https://app.test')));

        $this->assertSame($answered, $json->getData(true)['resume_until']);
    }

    public static function resumeUntils(): array
    {
        return [
            'Z' => ['2026-09-26T16:01:00Z', '2026-09-26T16:01:00+00:00'],
            'fraction' => ['2026-09-26T16:01:00.123456Z', '2026-09-26T16:01:00+00:00'],
            '-03:00' => ['2026-09-26T13:01:00-03:00', '2026-09-26T13:01:00-03:00'],
        ];
    }

    /** @dataProvider withoutAResumeUrl */
    #[DataProvider('withoutAResumeUrl')]
    public function testS4S6WithoutAResumeUrlBothAreNull(array $changes, array $without)
    {
        Log::spy();
        $client = $this->created(self::inbox($changes, $without));

        $json = $this->answer(EmbedSessionResponse::open(fn () => $client->inboxSession('https://app.test')));

        $this->assertSame(200, $json->getStatusCode());
        $this->assertNull($json->getData(true)['resume_url']);
        $this->assertNull($json->getData(true)['resume_until']);
        Log::shouldNotHaveReceived('warning');
    }

    public static function withoutAResumeUrl(): array
    {
        return [
            'S4 null' => [['resume_url' => null], []],
            'S4 absent' => [[], ['resume_url', 'resume_until']],
            'S6 resume until without resume url' => [[], ['resume_url']],
        ];
    }

    /** @dataProvider droppedResumeUrls */
    #[DataProvider('droppedResumeUrls')]
    public function testS5AResumeUrlOfAnotherOriginOrUnsafeIsDropped(string $resumeUrl)
    {
        Log::spy();
        $client = $this->created(self::inbox(['resume_url' => $resumeUrl]));

        $json = $this->answer(EmbedSessionResponse::open(fn () => $client->inboxSession('https://app.test')));

        $this->assertSame(200, $json->getStatusCode());
        $this->assertNull($json->getData(true)['resume_url']);
        $this->assertNull($json->getData(true)['resume_until']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'zapmizer: unexpected resume url.')->once();
    }

    public static function droppedResumeUrls(): array
    {
        return ['another origin' => ['https://evil.com/r'], 'unsafe' => ['javascript:alert(1)']];
    }

    public function testS7TheOriginIsTheOneTheBrowserShows()
    {
        $client = $this->created('{"url":"HTTPS://App.Zapmizer.com:443/x","expires_at":"2026-09-26T14:01:00+00:00"}');

        $json = $this->answer(EmbedSessionResponse::open(fn () => $client->conversationSession('5521988887777', 'https://app.test')));

        $this->assertSame('HTTPS://App.Zapmizer.com:443/x', $json->getData(true)['url']);
        $this->assertSame('https://app.zapmizer.com', $json->getData(true)['origin']);
    }

    public function testS8AnUnreadableResumeUntilIsNull()
    {
        Log::spy();
        $client = $this->created(self::inbox(['resume_until' => 'amanhã']));

        $json = $this->answer(EmbedSessionResponse::open(fn () => $client->inboxSession('https://app.test')));

        $this->assertSame(200, $json->getStatusCode());
        $this->assertSame(self::INBOX_SESSION['resume_url'], $json->getData(true)['resume_url']);
        $this->assertNull($json->getData(true)['resume_until']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'zapmizer: unreadable date.')->once();
    }

    public function testS9AnUnreadableExpiresAtStillOpens()
    {
        Log::spy();
        $client = $this->created('{"url":"https://app.parlichat.com/embed/start/Zr8kQ2","expires_at":"x"}');

        $json = $this->answer(EmbedSessionResponse::open(fn () => $client->conversationSession('5521988887777', 'https://app.test')));

        $this->assertSame(200, $json->getStatusCode());
        $this->assertSame(['url', 'origin', 'resume_url', 'resume_until'], array_keys($json->getData(true)));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'zapmizer: unreadable date.')->once();
    }

    public function testA1TheAppCode()
    {
        $json = $this->answer(EmbedSessionResponse::code('customer_without_phone'));

        $this->assertSame(422, $json->getStatusCode());
        $this->assertSame(['code' => 'customer_without_phone'], $json->getData(true));
    }

    /** @dataProvider appStatuses */
    #[DataProvider('appStatuses')]
    public function testA2TheAppCodeWithItsStatus(int $status)
    {
        $json = $this->answer(EmbedSessionResponse::code('x', $status));

        $this->assertSame($status, $json->getStatusCode());
        $this->assertSame(['code' => 'x'], $json->getData(true));
    }

    public static function appStatuses(): array
    {
        return ['404' => [404], '503' => [503]];
    }

    /** @dataProvider codesThatCannotBeARefusal */
    #[DataProvider('codesThatCannotBeARefusal')]
    public function testA3AnAppCodeThatCannotBeARefusal(string $code, int $status)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An embed refusal needs a non-empty code and a 4xx or 5xx status other than 401 and 419.');

        EmbedSessionResponse::code($code, $status);
    }

    public static function codesThatCannotBeARefusal(): array
    {
        return [
            '401' => ['x', 401],
            '419' => ['x', 419],
            '200' => ['x', 200],
            '302' => ['x', 302],
            '399' => ['x', 399],
            '600' => ['x', 600],
            'empty code' => ['', 422],
        ];
    }

    public function testO3ASubclassAddsAField()
    {
        $client = $this->created(self::CONVERSATION);

        $response = EmbedResponseWithExpiry::open(fn () => $client->conversationSession('5521988887777', 'https://app.test'));

        $this->assertInstanceOf(EmbedResponseWithExpiry::class, $response);
        $json = $this->answer($response);
        $this->assertSame(200, $json->getStatusCode());
        $this->assertSame([
            'url' => 'https://app.parlichat.com/embed/start/Zr8kQ2',
            'origin' => 'https://app.parlichat.com',
            'resume_url' => null,
            'resume_until' => null,
            'expires_at' => '2026-09-26T14:01:00+00:00',
        ], $json->getData(true));
        $this->assertInstanceOf(EmbedResponseWithExpiry::class, EmbedResponseWithExpiry::code('x'));
    }

    /** @dataProvider closuresThatDoNotReturnASession */
    #[DataProvider('closuresThatDoNotReturnASession')]
    public function testO5AClosureThatReturnsSomethingElseIsATypeError(\Closure $open)
    {
        $this->expectException(TypeError::class);

        EmbedSessionResponse::open($open);
    }

    public static function closuresThatDoNotReturnASession(): array
    {
        return [
            'string' => [fn () => 'x'],
            'null' => [fn () => null],
            'braces without return' => [function () {
                $forgotten = 1;
            }],
        ];
    }

    protected function hook(): \Closure
    {
        return function (ZapmizerApiException $exception) {
            $this->hooked[] = $exception;
        };
    }

    protected function openWith(Response|\Throwable ...$answers): EmbedSessionResponse
    {
        $client = $this->client(...$answers);

        return EmbedSessionResponse::open(fn () => $client->conversationSession('5521988887777', 'https://app.test'), $this->hook());
    }

    protected function assertCode(?string $code, \Illuminate\Http\JsonResponse $json): void
    {
        $this->assertSame(['code' => $code], $json->getData(true));

        if ($code === null) {
            $this->assertSame('{"code":null}', $json->getContent());
        }
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testRefusalFromTheContract(int $status, string $body, int $answered, ?string $code)
    {
        $this->assertMatchesContract('POST', '/embed/sessions', $status, $body);
        $this->recordReports();

        $json = $this->answer($this->openWith(new Response($status, ['Content-Type' => 'application/json'], $body)));

        $this->assertSame($answered, $json->getStatusCode());
        $this->assertCode($code, $json);
        $this->assertFalse($json->headers->has('Retry-After'));
        $this->assertCount(1, $this->hooked);
        $this->assertSame($status, $this->hooked[0]->status());
        if ($status === 401) {
            $this->assertInstanceOf(ZapmizerUnauthorizedException::class, $this->hooked[0]);
        }
        $this->assertSame([], $this->reported);
    }

    public static function refusalsFromTheContract(): array
    {
        return [
            'X3 402' => [402, '{"error":"subscription_required","message":"Sem assinatura."}', 402, 'subscription_required'],
            'X5 403 not a partner connection' => [403, '{"error":"not_a_partner_connection"}', 403, 'not_a_partner_connection'],
            'X5 403 origin not allowed' => [403, '{"error":"origin_not_allowed","message":"Origem não liberada."}', 403, 'origin_not_allowed'],
            'X5 403 missing ability' => [403, '{"error":"missing_ability"}', 403, 'missing_ability'],
            'X7 422 connection without number' => [422, '{"error":"connection_without_number","message":"Sem número."}', 422, 'connection_without_number'],
            'X7 422 number unavailable' => [422, '{"error":"number_unavailable"}', 422, 'number_unavailable'],
            'X7 422 approver without access' => [422, '{"error":"approver_without_access"}', 422, 'approver_without_access'],
            'X8 422 with errors' => [422, '{"message":"The phone field format is invalid.","errors":{"phone":["The phone field format is invalid."]}}', 422, null],
            'X8 422 only message' => [422, '{"message":"Invalid."}', 422, null],
            'X1 401' => [401, '{"message":"Unauthenticated."}', 422, 'reauth_required'],
        ];
    }

    /** @dataProvider refusalsOutsideTheContract */
    #[DataProvider('refusalsOutsideTheContract')]
    public function testRefusalOutsideTheContract(Response $response, int $answered, ?string $code)
    {
        $this->recordReports();

        $json = $this->answer($this->openWith($response));

        $this->assertSame($answered, $json->getStatusCode());
        $this->assertCode($code, $json);
        $this->assertCount(1, $this->hooked);
        $this->assertSame($response->getStatusCode(), $this->hooked[0]->status());
        $this->assertSame([], $this->reported);
    }

    public static function refusalsOutsideTheContract(): array
    {
        $json = ['Content-Type' => 'application/json'];

        return [
            'X4 402 without error' => [new Response(402, $json, '{"message":"Payment required."}'), 402, null],
            'X4 numeric error' => [new Response(402, $json, '{"error":402}'), 402, null],
            'X6 403 without error' => [new Response(403, $json, '{"message":"Forbidden."}'), 403, null],
            'X9 404 without error' => [new Response(404, $json, '{"message":"Not Found"}'), 404, null],
            'X9 404 with error' => [new Response(404, $json, '{"error":"x"}'), 404, 'x'],
            'X9b 400 in the Meta format' => [new Response(400, $json, '{"error":{"message":"Bad","type":"OAuthException","code":100}}'), 400, null],
            'X10 409 with error' => [new Response(409, $json, '{"error":"bot_offline"}'), 409, 'bot_offline'],
            'X10 409 without error' => [new Response(409, $json, '{"message":"Conflict."}'), 409, null],
            'X11 419' => [new Response(419, $json, '{"message":"Page Expired"}'), 422, 'reauth_required'],
        ];
    }

    public function testX9bTheHookSeesNoErrorInTheMetaFormat()
    {
        $this->answer($this->openWith(new Response(400, ['Content-Type' => 'application/json'], '{"error":{"message":"Bad","type":"OAuthException","code":100}}')));

        $this->assertCount(1, $this->hooked);
        $this->assertNull($this->hooked[0]->error());
    }

    public function testX2AMissingTokenIsReauthRequired()
    {
        Http::fake();

        $json = $this->answer(EmbedSessionResponse::open(
            fn () => app(InstanceClient::class, ['api_token' => null])->inboxSession('https://app.test'),
            $this->hook(),
        ));

        $this->assertSame(422, $json->getStatusCode());
        $this->assertCode('reauth_required', $json);
        $this->assertCount(1, $this->hooked);
        $this->assertInstanceOf(ZapmizerUnauthorizedException::class, $this->hooked[0]);
        $this->assertSame(401, $this->hooked[0]->status());
        Http::assertNothingSent();
    }

    /** @dataProvider retryAfters */
    #[DataProvider('retryAfters')]
    public function testX12ToX14TheRetryAfter(array $headers, ?string $retryAfter)
    {
        $this->recordReports();

        $json = $this->answer($this->openWith(new Response(429, ['Content-Type' => 'application/json'] + $headers, '{"message":"Too Many Attempts."}')));

        $this->assertSame(429, $json->getStatusCode());
        $this->assertCode('rate_limited', $json);
        $this->assertSame($retryAfter, $json->headers->get('Retry-After'));
        $this->assertCount(1, $this->hooked);
        $this->assertSame([], $this->reported);
    }

    public static function retryAfters(): array
    {
        return [
            'X12 30' => [['Retry-After' => '30'], '30'],
            'X13 absent' => [[], null],
            'X14 http date' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null],
            'X14 fraction' => [['Retry-After' => '30.5'], null],
            'X14 negative' => [['Retry-After' => '-1'], null],
            'X14 empty' => [['Retry-After' => ''], null],
            'X14 spaces' => [['Retry-After' => ' 30 '], '30'],
            'X14 zero' => [['Retry-After' => '0'], '0'],
            'X14b above PHP_INT_MAX' => [['Retry-After' => '99999999999999999999'], '9223372036854775807'],
        ];
    }

    /** @dataProvider downOrNoAnswer */
    #[DataProvider('downOrNoAnswer')]
    public function testX15X16DownOrNoAnswerIsUnavailable(Response|\Throwable $answer)
    {
        $this->recordReports();

        $json = $this->answer($this->openWith($answer));

        $this->assertSame(503, $json->getStatusCode());
        $this->assertCode('unavailable', $json);
        $this->assertSame([], $this->hooked);
        $this->assertSame([], $this->reported);
    }

    public static function downOrNoAnswer(): array
    {
        return [
            'X15 500' => [new Response(500, [], 'boom')],
            'X15 502' => [new Response(502, [], 'bad gateway')],
            'X15 503' => [new Response(503, [], 'down')],
            'X16 timeout' => [new ConnectException('timed out', new PsrRequest('POST', 'embed/sessions'))],
        ];
    }

    /** @dataProvider answersThatAreNotASession */
    #[DataProvider('answersThatAreNotASession')]
    public function testX17AnAnswerThatIsNotASessionIsUnavailableAndReported(Response $response)
    {
        $this->recordReports();

        $json = $this->answer($this->openWith($response));

        $this->assertSame(503, $json->getStatusCode());
        $this->assertCode('unavailable', $json);
        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(ZapmizerConnectException::class, $this->reported[0]);
        $this->assertNotInstanceOf(ZapmizerApiException::class, $this->reported[0]);
        $this->assertSame([], $this->hooked);
    }

    public static function answersThatAreNotASession(): array
    {
        return array_map(fn (array $row) => [$row[0]], InstanceClientEmbedTest::answersThatAreNotASession());
    }

    public function testAnAppWideHttpFakeIsUnavailableAndReported()
    {
        Http::fake();
        $this->recordReports();

        $json = $this->answer(EmbedSessionResponse::open(
            fn () => (new InstanceClient('team-token', new LaravelHttpTransport(), 'http://localhost/api'))->inboxSession('https://app.test'),
            $this->hook(),
        ));

        $this->assertSame(503, $json->getStatusCode());
        $this->assertCode('unavailable', $json);
        $this->assertCount(1, $this->reported);
        $this->assertSame([], $this->hooked);
    }

    /** @dataProvider invalidBaseUris */
    #[DataProvider('invalidBaseUris')]
    public function testX18AnInvalidBaseIsUnavailableReportedAndSendsNothing(string $base)
    {
        Http::fake();
        $this->recordReports();

        $json = $this->answer(EmbedSessionResponse::open(
            fn () => (new InstanceClient('team-token', new LaravelHttpTransport(), $base))->conversationSession('5521988887777', 'https://app.test'),
            $this->hook(),
        ));

        $this->assertSame(503, $json->getStatusCode());
        $this->assertCode('unavailable', $json);
        $this->assertCount(1, $this->reported);
        $this->assertIsTheInvalidBaseUri($this->reported[0]);
        $this->assertSame([], $this->hooked);
        Http::assertNothingSent();
    }

    /** @dataProvider clientErrors */
    #[DataProvider('clientErrors')]
    public function testX19AClientErrorNeverAnswers401Or419(int $status)
    {
        $json = $this->answer($this->openWith(new Response($status, ['Content-Type' => 'application/json'], '{"error":"x"}')));

        $this->assertNotContains($json->getStatusCode(), [401, 419]);
        $this->assertSame(in_array($status, [401, 419], true) ? 422 : $status, $json->getStatusCode());
    }

    public static function clientErrors(): array
    {
        $statuses = range(400, 499);

        return array_combine(array_map('strval', $statuses), array_map(fn (int $status) => [$status], $statuses));
    }

    public function testX20NotConnectedIsUnavailableAndReported()
    {
        $this->recordReports();
        $exception = ZapmizerConnectException::notConnected();

        $json = $this->answer(EmbedSessionResponse::open(fn () => throw $exception, $this->hook()));

        $this->assertSame(503, $json->getStatusCode());
        $this->assertCode('unavailable', $json);
        $this->assertCount(1, $this->reported);
        $this->assertSame($exception, $this->reported[0]);
        $this->assertSame([], $this->hooked);
    }

    public function testX21AMalformedNameIsTheCallersError()
    {
        $this->recordReports();
        $client = $this->created(self::CONVERSATION);

        try {
            EmbedSessionResponse::open(fn () => $client->conversationSession('5521988887777', 'https://app.test', [], 'u-1', "Ana \xB1"), $this->hook());
            $this->fail('Expected GuzzleHttp\Exception\InvalidArgumentException.');
        } catch (\GuzzleHttp\Exception\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertCount(0, $this->history);
        $this->assertSame([], $this->reported);
        $this->assertSame([], $this->hooked);
    }

    public function testO4AHookThatThrowsEscapesTheOpen()
    {
        $client = $this->client(new Response(403, ['Content-Type' => 'application/json'], '{"error":"origin_not_allowed"}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('app bug');

        EmbedSessionResponse::open(
            fn () => $client->conversationSession('5521988887777', 'https://app.test'),
            fn () => throw new RuntimeException('app bug'),
        );
    }

    public function testWhatTheHookReturnsIsIgnored()
    {
        $client = $this->client(new Response(403, ['Content-Type' => 'application/json'], '{"error":"origin_not_allowed"}'));

        $json = $this->answer(EmbedSessionResponse::open(
            fn () => $client->conversationSession('5521988887777', 'https://app.test'),
            fn () => response('x', 500),
        ));

        $this->assertSame(403, $json->getStatusCode());
        $this->assertCode('origin_not_allowed', $json);
    }

    public function testO6TheRouterAnswersTheRefusal()
    {
        $client = $this->client(new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '30'], '{"message":"Too Many Attempts."}'));
        Route::post('/embed-test', fn () => EmbedSessionResponse::open(fn () => $client->inboxSession('https://app.test')));

        $this->postJson('/embed-test')
            ->assertStatus(429)
            ->assertExactJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After', '30');
    }

    public function testO6TheRouterAnswersTheSession()
    {
        $client = $this->created(self::INBOX);
        Route::post('/embed-test', fn () => EmbedSessionResponse::open(fn () => $client->inboxSession('https://app.test')));

        $this->postJson('/embed-test')
            ->assertOk()
            ->assertExactJson(self::INBOX_SESSION);
    }
}
