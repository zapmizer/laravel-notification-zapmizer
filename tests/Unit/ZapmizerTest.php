<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Connect\ZapmizerApi;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Concerns\InvalidBaseUris;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\Fixtures\SendsThroughZapmizer;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Zapmizer;
use NotificationChannels\Zapmizer\ZapmizerMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

class ZapmizerTest extends TestCase
{
    use AssertsContract;
    use FakesZapmizerHttp;
    use InvalidBaseUris;

    /** @var array<int, array{request: Request, options: array}> */
    protected array $history = [];

    protected function makeClient(Response ...$responses): Zapmizer
    {
        $this->fakeZapmizer(new MockHandler($responses));

        return new Zapmizer('bot-token', new LaravelHttpTransport(), 'http://localhost/api', '2025-06-27');
    }

    protected function params(): array
    {
        return ['type' => 'chat', 'from' => '5581999990000', 'to' => '5511999999999', 'metadata' => ['text' => 'hi']];
    }

    public function testOverrideDefaultConfig()
    {
        config()->set('zapmizer.api_token', 'test');

        $this->assertEquals('test', app(Zapmizer::class)->getToken());
        $this->assertEquals('prod', app(Zapmizer::class, ['api_token' => 'prod'])->getToken());
    }

    public function testSendMessageAsksForJsonAndDoesNotFollowRedirects()
    {
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 1}');
        $client = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], '{"id": 1}'));

        $sent = $client->sendMessage($this->params());

        $this->assertSame('1', $sent->id);

        $request = $this->history[0]['request'];
        $this->assertEquals('http://localhost/api/messages', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Accept'));
        $this->assertEquals('Bearer bot-token', $request->getHeaderLine('Authorization'));
        $this->assertEquals('2025-06-27', $request->getHeaderLine('api-version'));
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testTheTextGoesAsAFormAndTheFileAsMultipart()
    {
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 1}');
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 2}');
        $client = $this->makeClient(
            new Response(200, ['Content-Type' => 'application/json'], '{"id": 1}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"id": 2}'),
        );

        $client->sendMessage($this->params());
        $client->sendMessageWithFile(['type' => 'document', 'from' => '5581999990000', 'to' => '5511999999999'], __FILE__);

        $form = $this->history[0]['request'];
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $form->getHeaderLine('Content-Type'));
        parse_str((string) $form->getBody(), $fields);
        $this->assertSame('hi', $fields['metadata']['text']);

        $multipart = $this->history[1]['request'];
        $this->assertEquals('http://localhost/api/messages', (string) $multipart->getUri());
        $this->assertStringStartsWith('multipart/form-data', $multipart->getHeaderLine('Content-Type'));
        $body = (string) $multipart->getBody();
        $this->assertStringContainsString('name="uploaded_media"; filename="ZapmizerTest.php"', $body);
        $this->assertStringContainsString('class ZapmizerTest extends TestCase', $body);
        $this->assertStringContainsString('name="type"', $body);
    }

    public function testAFileWithNestedMetadataSendsTheNestedField()
    {
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 1}');
        $client = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], '{"id": 1}'));

        $client->sendMessageWithFile(['type' => 'image', 'text' => 'caption', 'metadata' => ['text' => 'hi']], __FILE__);

        $this->assertStringStartsWith('multipart/form-data', $this->history[0]['request']->getHeaderLine('Content-Type'));
        $body = (string) $this->history[0]['request']->getBody();
        $this->assertStringContainsString('name="metadata[text]"', $body);
        $this->assertStringContainsString('name="text"', $body);
    }

    public function testAnEmptyFakeIsNotAnApiAnswer()
    {
        Http::fake();

        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('response body is not valid JSON');

        app(Zapmizer::class, ['api_token' => 'bot-token'])->sendMessage(['type' => 'chat', 'to' => '5511999999999']);
    }

    public function testAZapmizerMessageSendsThroughTheHttpFake()
    {
        config()->set('zapmizer.api_token', 'team-token');
        config()->set('zapmizer.base_uri', 'https://zapmizer.test/api/');
        Http::fake(['zapmizer.test/*' => Http::response(['id' => 1], 200)]);

        ZapmizerMessage::create(from: '5581911110000', to: '5511999999999')->text('Hello')->send();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://zapmizer.test/api/messages'
            && $request['to'] === '5511999999999'
            && $request['metadata']['text'] === 'Hello');
    }

    public function testARefusedZapmizerMessageThrowsTheApiException()
    {
        config()->set('zapmizer.api_token', 'team-token');
        config()->set('zapmizer.base_uri', 'https://zapmizer.test/api/');
        Http::fake(['zapmizer.test/*' => Http::response(['error' => 'window_closed', 'message' => 'The 24 h window is closed.'], 409)]);

        try {
            ZapmizerMessage::create(from: '5581911110000', to: '5511999999999')->text('Hello')->send();
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(409, $exception->status());
            $this->assertSame(ErrorCode::WINDOW_CLOSED, $exception->error());
        }
    }

    /**
     * A revoked token makes Zapmizer redirect to its login page. Followed,
     * that is an HTML 200 — and a message silently lost.
     *
     * @dataProvider nonApiAnswers
     */
    #[DataProvider('nonApiAnswers')]
    public function testM24AndM25SendMessageRefusesAnAnswerThatIsNotJson(Response $response)
    {
        $client = $this->makeClient($response);

        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('Zapmizer returned an unexpected response.');

        $client->sendMessage($this->params());
    }

    /** @dataProvider nonApiAnswers */
    #[DataProvider('nonApiAnswers')]
    public function testM24AndM25SendMessageWithFileRefusesAnAnswerThatIsNotJson(Response $response)
    {
        $client = $this->makeClient($response);

        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('Zapmizer returned an unexpected response.');

        $client->sendMessageWithFile($this->params(), __FILE__);
    }

    public static function nonApiAnswers(): array
    {
        return [
            'redirect to login' => [new Response(302, ['Location' => 'http://localhost/login'], '')],
            'html page' => [new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html>login</html>')],
            'no content type, not json' => [new Response(200, [], '<html>login</html>')],
        ];
    }

    public function testTheRedirectMessageNamesTheLikelyCause()
    {
        $client = $this->makeClient(new Response(302, ['Location' => 'http://localhost/login'], ''));

        try {
            $client->sendMessage($this->params());
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertNotInstanceOf(ZapmizerApiException::class, $exception);
            $this->assertStringContainsString('302', $exception->getMessage());
            $this->assertStringContainsString('http://localhost/login', $exception->getMessage());
            $this->assertStringContainsString('token', $exception->getMessage());
        }
    }

    public function testAJsonAnswerWithoutContentTypeIsAccepted()
    {
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 1}');
        $client = $this->makeClient(new Response(200, [], '{"id": 1}'));

        $this->assertSame(['id' => 1], $client->sendMessage($this->params())->raw());
    }

    public function testSendMessageAnswersTheTypedSend()
    {
        $this->assertMatchesContract('POST', '/messages', 200, SentMessageTest::QR);
        $this->assertMatchesContract('POST', '/messages', 200, SentMessageTest::OFFICIAL);
        $client = $this->makeClient(
            new Response(200, ['Content-Type' => 'application/json'], SentMessageTest::QR),
            new Response(200, ['Content-Type' => 'application/json'], SentMessageTest::OFFICIAL),
        );

        $qr = $client->sendMessage($this->params());
        $official = $client->sendMessageWithFile($this->params(), __FILE__);

        $this->assertInstanceOf(SentMessage::class, $qr);
        $this->assertSame('9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $qr->id);
        $this->assertSame('https://app.zapmizer.com/api/sent-messages/9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $qr->selfUrl);
        $this->assertSame('551199999999@c.us', $qr->widTo);
        $this->assertSame(json_decode(SentMessageTest::QR, true), $qr->raw());
        $this->assertSame('42', $official->id);
        $this->assertSame('https://app.zapmizer.com/api/meta-messages/42', $official->selfUrl);
    }

    public function testTheLegacyAnswerIsReadToo()
    {
        $this->assertMatchesContract('GET', '/messages/{id}', 200, SentMessageTest::LEGACY);
        $this->fakeZapmizer(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], SentMessageTest::LEGACY)]));
        $client = new Zapmizer('bot-token', new LaravelHttpTransport(), 'http://localhost/api');

        $sent = $client->sendMessage($this->params());

        $this->assertSame('', $this->history[0]['request']->getHeaderLine('api-version'));
        $this->assertSame('7', $sent->id);
        $this->assertSame('551199999999@c.us', $sent->widTo);
    }

    /** @dataProvider jsonThatIsNotAnObject */
    #[DataProvider('jsonThatIsNotAnObject')]
    public function testAJsonAnswerThatIsNotAnObjectIsUnexpected(string $body)
    {
        $client = $this->makeClient(
            new Response(200, ['Content-Type' => 'application/json'], $body),
            new Response(200, ['Content-Type' => 'application/json'], $body),
        );

        foreach ([fn () => $client->sendMessage($this->params()), fn () => $client->sendMessageWithFile($this->params(), __FILE__)] as $send) {
            try {
                $send();
                $this->fail('Expected unexpectedResponse.');
            } catch (ZapmizerConnectException $exception) {
                $this->assertNotInstanceOf(ZapmizerApiException::class, $exception);
                $this->assertStringContainsString('response body is not a JSON object', $exception->getMessage());
            }
        }
    }

    public static function jsonThatIsNotAnObject(): array
    {
        return [
            'empty body' => [''],
            'list' => ['[1, 2]'],
            'string' => ['"ok"'],
            'null' => ['null'],
            'true' => ['true'],
        ];
    }

    public function testAZapmizerMessageAnswersTheTypedSend()
    {
        $this->assertMatchesContract('POST', '/messages', 200, SentMessageTest::QR);
        config()->set('zapmizer.api_token', 'team-token');
        config()->set('zapmizer.base_uri', 'https://zapmizer.test/api/');
        Http::fake(['zapmizer.test/*' => Http::response(SentMessageTest::QR, 200, ['Content-Type' => 'application/json'])]);

        $text = ZapmizerMessage::create(from: '5581911110000', to: '5511999999999')->text('Hello')->send();
        $file = ZapmizerMessage::create(from: '5581911110000', to: '5511999999999')->document(__FILE__, 'caption')->send();

        $this->assertInstanceOf(SentMessage::class, $text);
        $this->assertSame('9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $text->id);
        $this->assertInstanceOf(SentMessage::class, $file);
        Http::assertSentCount(2);
    }

    public function testNotificationSentCarriesTheTypedSend()
    {
        $this->assertMatchesContract('POST', '/messages', 200, SentMessageTest::QR);
        config()->set('zapmizer.api_token', 'team-token');
        config()->set('zapmizer.base_uri', 'https://zapmizer.test/api/');
        Http::fake(['zapmizer.test/*' => Http::response(SentMessageTest::QR, 200, ['Content-Type' => 'application/json'])]);
        $events = [];
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$events) {
            $events[] = $event;
        });

        (new AnonymousNotifiable())->route('zapmizer', '5511999999999')->notify(new SendsThroughZapmizer());

        $this->assertCount(1, $events);
        $this->assertSame('zapmizer', $events[0]->channel);
        $this->assertInstanceOf(SentMessage::class, $events[0]->response['message']);
        $this->assertSame('9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $events[0]->response['message']->id);
    }

    public function testASubclassThatOverridesPostStillSends()
    {
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 9}');
        $transport = new RecordingTransport(new Response(200, ['Content-Type' => 'application/json'], '{"id": 9}'));
        $client = new class('bot-token', $transport, 'http://localhost/api') extends Zapmizer {
            public array $posted = [];

            protected function post(array $options): ResponseInterface
            {
                $this->posted[] = $options;

                return parent::post($options);
            }
        };

        $this->assertSame('9', $client->sendMessage($this->params())->id);
        $this->assertCount(1, $client->posted);
    }

    public function testM22UnauthorizedIsTheTokenRefusal()
    {
        $this->assertMatchesContract('POST', '/messages', 401, '{"message":"Unauthenticated."}');
        $client = $this->makeClient(new Response(401, ['Content-Type' => 'application/json'], '{"message":"Unauthenticated."}'));

        try {
            $client->sendMessage($this->params());
            $this->fail('Expected ZapmizerUnauthorizedException.');
        } catch (ZapmizerUnauthorizedException $exception) {
            $this->assertSame(401, $exception->status());
            $this->assertSame('Unauthenticated.', $exception->reason());
            $this->assertSame('Zapmizer refused the connection token.', $exception->getMessage());
        }
    }

    public function testM21NotFoundIsAnApiException()
    {
        $this->assertMatchesContract('POST', '/messages', 404, '{"message":"Not found."}');
        $client = $this->makeClient(new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not found."}'));

        try {
            $client->sendMessageWithFile($this->params(), __FILE__);
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(404, $exception->status());
            $this->assertStringContainsString('HTTP 404', $exception->getMessage());
        }
    }

    public function testM23ServerErrorAndNetworkFailureAreUnavailable()
    {
        $network = new ConnectException('timed out', new Request('POST', 'http://localhost/api/messages'));
        $this->fakeZapmizer(new MockHandler([new Response(503, [], ''), $network]));
        $client = new Zapmizer('bot-token', new LaravelHttpTransport(), 'http://localhost/api');

        try {
            $client->sendMessage($this->params());
            $this->fail('Expected ZapmizerUnavailableException.');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertNull($exception->getPrevious());
        }

        try {
            $client->sendMessage($this->params());
            $this->fail('Expected ZapmizerUnavailableException.');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertSame($network, $exception->getPrevious()->getPrevious());
        }
    }

    public function testM26WithoutTokenFailsBeforeAnyRequest()
    {
        $transport = new RecordingTransport();
        $client = new Zapmizer(null, $transport, 'http://localhost/api');

        foreach ([fn () => $client->sendMessage($this->params()), fn () => $client->sendMessageWithFile($this->params(), __FILE__)] as $send) {
            try {
                $send();
                $this->fail('Expected ZapmizerUnauthorizedException.');
            } catch (ZapmizerUnauthorizedException $exception) {
                $this->assertSame(401, $exception->status());
                $this->assertSame('You must provide your zapmizer bot token to make any API requests.', $exception->getMessage());
            }
        }

        $this->assertSame([], $transport->calls);
    }

    public function testM27AFileThatDoesNotOpenFailsBeforeAnyRequest()
    {
        $transport = new RecordingTransport();
        $client = new Zapmizer('bot-token', $transport, 'http://localhost/api');
        $missing = sys_get_temp_dir() . '/zapmizer-missing-' . uniqid() . '.png';

        try {
            $client->sendMessageWithFile($this->params(), $missing);
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertSame("Could not open {$missing} to send.", $exception->getMessage());
        }

        $this->assertSame([], $transport->calls);
    }

    public function testM30AndM45TheConfiguredTransportCarriesTheTimeoutsAndTheUploadHasItsOwn()
    {
        config()->set('zapmizer.api_token', 'bot-token');
        config()->set('zapmizer.base_uri', 'http://localhost/api/');
        config()->set('zapmizer.http.connect_timeout', 2);
        config()->set('zapmizer.http.timeout', 5);
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 1}');
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 2}');
        $this->fakeZapmizer(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"id": 1}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"id": 2}'),
        ]));

        app(Zapmizer::class)->sendMessage($this->params());
        app(Zapmizer::class)->sendMessageWithFile($this->params(), __FILE__);

        $this->assertCount(2, $this->history);
        $this->assertSame(2.0, $this->history[0]['options']['connect_timeout']);
        $this->assertSame(5.0, $this->history[0]['options']['timeout']);
        $this->assertSame(ZapmizerApi::TRANSFER_CONNECT_TIMEOUT, $this->history[1]['options']['connect_timeout']);
        $this->assertSame(ZapmizerApi::TRANSFER_TIMEOUT, $this->history[1]['options']['timeout']);
        $this->assertSame(60, ZapmizerApi::TRANSFER_CONNECT_TIMEOUT);
        $this->assertSame(600, ZapmizerApi::TRANSFER_TIMEOUT);
    }

    public function testM46AMalformedBaseUriIsTheInvalidBaseUri()
    {
        $this->fakeZapmizer(new MockHandler([new Response(200)]));
        $client = new Zapmizer('bot-token', new LaravelHttpTransport(), 'http://:80/api');

        try {
            $client->sendMessage($this->params());
            $this->fail('Expected the invalid base URI.');
        } catch (ZapmizerConnectException $e) {
            $this->assertIsTheInvalidBaseUri($e);
        }

        $this->assertCount(0, $this->history);
    }

    /** @dataProvider withAndWithoutJson */
    #[DataProvider('withAndWithoutJson')]
    public function testM43TheAppDecidesTheResponseToAFailedSend(array $headers)
    {
        config()->set('zapmizer.api_token', 'bot-token');
        config()->set('zapmizer.base_uri', 'http://localhost/api/');
        $this->fakeZapmizer(new MockHandler([new Response(503, [], '')]));
        $this->app->make(ExceptionHandler::class)->renderable(fn (ZapmizerUnavailableException $exception) => response('the app decides', 502));
        Route::get('app-sends', function () {
            app(Zapmizer::class)->sendMessage(['type' => 'chat', 'from' => '5581999990000', 'to' => '5511999999999']);

            return 'sent';
        });

        $this->get('app-sends', $headers)->assertStatus(502)->assertSee('the app decides');
    }

    public static function withAndWithoutJson(): array
    {
        return [
            'Accept: application/json' => [['Accept' => 'application/json']],
            'browser Accept' => [['Accept' => 'text/html']],
        ];
    }

    /** @dataProvider invalidBaseUris */
    #[DataProvider('invalidBaseUris')]
    public function testX18bAnInvalidBaseSendsNothing(string $base)
    {
        Http::fake();

        try {
            (new Zapmizer('bot-token', new LaravelHttpTransport(), $base))->sendMessage($this->params());
            $this->fail('Expected the invalid base URI.');
        } catch (ZapmizerConnectException $e) {
            $this->assertIsTheInvalidBaseUri($e);
        }

        Http::assertNothingSent();
    }

    public function testAMissingTokenComesBeforeTheBaseCheck()
    {
        Http::fake();

        try {
            (new Zapmizer(null, new LaravelHttpTransport(), 'localhost/api/'))->sendMessage($this->params());
            $this->fail('Expected ZapmizerUnauthorizedException.');
        } catch (ZapmizerUnauthorizedException $e) {
            $this->assertSame('You must provide your zapmizer bot token to make any API requests.', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
