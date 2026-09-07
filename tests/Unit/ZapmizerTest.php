<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Exceptions\CouldNotSendNotification;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Zapmizer;
use PHPUnit\Framework\Attributes\DataProvider;

class ZapmizerTest extends TestCase
{
    /** @var array<int, array{request: Request, options: array}> */
    protected array $history = [];

    protected function makeClient(Response ...$responses): Zapmizer
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Zapmizer('bot-token', new HttpClient(['handler' => $stack]), 'http://localhost/api', '2025-06-27');
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
        $client = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], '{"id": 1}'));

        $response = $client->sendMessage($this->params());

        $this->assertEquals(200, $response->getStatusCode());

        $request = $this->history[0]['request'];
        $this->assertEquals('http://localhost/api/messages', (string) $request->getUri());
        $this->assertEquals('application/json', $request->getHeaderLine('Accept'));
        $this->assertEquals('Bearer bot-token', $request->getHeaderLine('Authorization'));
        $this->assertEquals('2025-06-27', $request->getHeaderLine('api-version'));
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }

    /**
     * A revoked token makes Zapmizer redirect to its login page. Followed,
     * that is an HTML 200 — and a message silently lost.
     */
    #[DataProvider('nonApiAnswers')]
    public function testSendMessageRefusesAnAnswerThatIsNotJson(Response $response)
    {
        $client = $this->makeClient($response);

        $this->expectException(CouldNotSendNotification::class);

        $client->sendMessage($this->params());
    }

    #[DataProvider('nonApiAnswers')]
    public function testSendMessageWithFileRefusesAnAnswerThatIsNotJson(Response $response)
    {
        $client = $this->makeClient($response);

        $this->expectException(CouldNotSendNotification::class);

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
            $this->fail('Expected CouldNotSendNotification.');
        } catch (CouldNotSendNotification $exception) {
            $this->assertStringContainsString('302', $exception->getMessage());
            $this->assertStringContainsString('http://localhost/login', $exception->getMessage());
            $this->assertStringContainsString('token', $exception->getMessage());
        }
    }

    public function testSendMessageWithoutTokenFailsBeforeAnyRequest()
    {
        $client = new Zapmizer(null, new HttpClient(), 'http://localhost/api');

        $this->expectException(CouldNotSendNotification::class);

        $client->sendMessage($this->params());
    }
}
