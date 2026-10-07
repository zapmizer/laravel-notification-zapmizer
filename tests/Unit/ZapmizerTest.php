<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Zapmizer;
use PHPUnit\Framework\Attributes\DataProvider;

class ZapmizerTest extends TestCase
{
    use AssertsContract;

    /** @var array<int, array{request: Request, options: array}> */
    protected array $history = [];

    protected function guzzle(array $queue): HttpClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new HttpClient(['handler' => $stack]);
    }

    protected function makeClient(Response ...$responses): Zapmizer
    {
        return new Zapmizer('bot-token', new GuzzleTransport($this->guzzle($responses)), 'http://localhost/api', '2025-06-27');
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

        $response = $client->sendMessage($this->params());

        $this->assertEquals(200, $response->getStatusCode());

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

        $body = (string) $this->history[0]['request']->getBody();
        $this->assertStringContainsString('name="metadata[text]"', $body);
        $this->assertStringContainsString('name="text"', $body);
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

        $this->assertSame('{"id": 1}', (string) $client->sendMessage($this->params())->getBody());
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
        $client = $this->makeClient(new Response(503, [], ''));
        $offline = new Zapmizer('bot-token', new GuzzleTransport($this->guzzle([$network])), 'http://localhost/api');

        try {
            $client->sendMessage($this->params());
            $this->fail('Expected ZapmizerUnavailableException.');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertNull($exception->getPrevious());
        }

        try {
            $offline->sendMessage($this->params());
            $this->fail('Expected ZapmizerUnavailableException.');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertSame($network, $exception->getPrevious());
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
        $this->app->instance(HttpClient::class, $this->guzzle([
            new Response(200, ['Content-Type' => 'application/json'], '{"id": 1}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"id": 2}'),
        ]));

        app(Zapmizer::class)->sendMessage($this->params());
        app(Zapmizer::class)->sendMessageWithFile($this->params(), __FILE__);

        $this->assertCount(2, $this->history);
        $this->assertSame(2.0, $this->history[0]['options']['connect_timeout']);
        $this->assertSame(5.0, $this->history[0]['options']['timeout']);
        $this->assertSame(Zapmizer::UPLOAD_CONNECT_TIMEOUT, $this->history[1]['options']['connect_timeout']);
        $this->assertSame(Zapmizer::UPLOAD_TIMEOUT, $this->history[1]['options']['timeout']);
        $this->assertSame(60, Zapmizer::UPLOAD_CONNECT_TIMEOUT);
        $this->assertSame(600, Zapmizer::UPLOAD_TIMEOUT);
    }

    public function testM46AMalformedBaseUriEscapesUnwrapped()
    {
        $client = new Zapmizer('bot-token', new GuzzleTransport($this->guzzle([new Response(200)])), 'http://:80/api');

        $this->expectException(MalformedUriException::class);

        $client->sendMessage($this->params());
    }

    /** @dataProvider withAndWithoutJson */
    #[DataProvider('withAndWithoutJson')]
    public function testM43TheAppDecidesTheResponseToAFailedSend(array $headers)
    {
        config()->set('zapmizer.api_token', 'bot-token');
        config()->set('zapmizer.base_uri', 'http://localhost/api/');
        $this->app->instance(HttpClient::class, $this->guzzle([new Response(503, [], '')]));
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
}
