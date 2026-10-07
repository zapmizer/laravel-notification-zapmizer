<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use App\Listeners\StoreInboundMedia;
use App\Zapmizer\TracingTransport;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\TestCase;

class ExamplesTest extends TestCase
{
    use CreatesConnectionTables;

    protected array $history = [];

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.base_uri', 'http://zap.test/api/');
        $app['config']->set('zapmizer.partner.id', 'id');
        $app['config']->set('zapmizer.partner.secret', 'secret');
    }

    protected function fakeGuzzle(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $this->instance(HttpClient::class, new HttpClient(['handler' => $stack]));
    }

    protected function message(bool $hasMedia = true): InboundMessage
    {
        return InboundMessage::fromMessage([
            'id' => ['_serialized' => 'true_5581999998888@c.us_3EB0ABC123', 'id' => '3EB0ABC123'],
            'from' => '5581999998888@c.us',
            'type' => $hasMedia ? 'image' : 'chat',
            'hasMedia' => $hasMedia,
            'timestamp' => 1700000000,
            '_data' => $hasMedia ? ['mimetype' => 'image/png'] : [],
        ], '5581911110000@c.us');
    }

    public function testTracingTransportIsResolvedFromConfigAndTagsTheRequest()
    {
        config()->set('zapmizer.http.transport', TracingTransport::class);
        $this->fakeGuzzle(new Response(201, ['Content-Type' => 'application/json'], json_encode(['url' => 'http://zap.test/c/1', 'expires_at' => null])));
        Log::spy();

        $this->assertInstanceOf(TracingTransport::class, $this->app->make(Transport::class));

        $session = app(PartnerClient::class)->createSession('https://app.test/cb', 'state-1');

        $this->assertSame('http://zap.test/c/1', $session->url);
        $request = $this->history[0]['request'];
        $this->assertNotSame('', $request->getHeaderLine('X-Request-Id'));
        $this->assertSame('id|secret', $request->getHeaderLine('X-Partner-Key'));
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $context['method'] === 'POST'
            && $context['url'] === 'http://zap.test/api/connect/sessions'
            && $context['status'] === 201
            && $context['request_id'] === $request->getHeaderLine('X-Request-Id'));
    }

    public function testTracingTransportDoesNotThrowOn4xxOrFollowRedirects()
    {
        config()->set('zapmizer.http.transport', TracingTransport::class);
        $this->fakeGuzzle(new Response(302, ['Location' => 'http://zap.test/login']));
        Log::spy();

        $response = $this->app->make(Transport::class)->send('GET', 'http://zap.test/api/x');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $this->history);
        Log::shouldHaveReceived('info')->once();
    }

    public function testStoreInboundMediaWritesTheFileToTheDisk()
    {
        Storage::fake('media');
        config()->set('services.zapmizer.media_disk', 'media');
        $this->fakeGuzzle(new Response(200, ['Content-Type' => 'image/png'], 'png-bytes'));
        $connection = $this->connectedTeam()->zapmizerConnection;

        (new StoreInboundMedia())->handle(new MessageReceived($this->message(), $connection, []));

        $files = Storage::disk('media')->allFiles();
        $this->assertSame(["zapmizer/{$connection->getKey()}/true_5581999998888_c_us_3EB0ABC123.png"], $files);
        $this->assertSame('png-bytes', Storage::disk('media')->get($files[0]));
    }

    public function testStoreInboundMediaIsIdempotentOnRetries()
    {
        Storage::fake('media');
        config()->set('services.zapmizer.media_disk', 'media');
        $this->fakeGuzzle(new Response(200, ['Content-Type' => 'image/png'], 'png-bytes'));
        $connection = $this->connectedTeam()->zapmizerConnection;
        $event = new MessageReceived($this->message(), $connection, []);

        (new StoreInboundMedia())->handle($event);
        (new StoreInboundMedia())->handle($event);

        $this->assertCount(1, $this->history);
        $this->assertCount(1, Storage::disk('media')->allFiles());
    }

    public function testStoreInboundMediaStoresNothingWhenUnavailable()
    {
        Storage::fake('media');
        config()->set('services.zapmizer.media_disk', 'media');
        $this->fakeGuzzle(new Response(404, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'unavailable'])));
        $connection = $this->connectedTeam()->zapmizerConnection;

        (new StoreInboundMedia())->handle(new MessageReceived($this->message(), $connection, []));

        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function testStoreInboundMediaIgnoresMessagesWithoutMedia()
    {
        Storage::fake('media');
        config()->set('services.zapmizer.media_disk', 'media');
        $this->fakeGuzzle();
        $connection = $this->connectedTeam()->zapmizerConnection;

        (new StoreInboundMedia())->handle(new MessageReceived($this->message(hasMedia: false), $connection, []));

        $this->assertSame([], $this->history);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }
}
