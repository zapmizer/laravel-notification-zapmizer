<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Concerns\InvalidBaseUris;
use NotificationChannels\Zapmizer\Test\Fixtures\EmbedResponseWithExpiry;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testO5AClosureThatReturnsSomethingElseIsATypeError()
    {
        $this->expectException(TypeError::class);

        EmbedSessionResponse::open(fn () => 'x');
    }
}
