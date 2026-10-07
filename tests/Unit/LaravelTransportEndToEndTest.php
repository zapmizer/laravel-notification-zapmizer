<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\Test\TestCase;
use RuntimeException;

class LaravelTransportEndToEndTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('zapmizer.base_uri', 'http://zap.test/api/');
        $app['config']->set('zapmizer.partner.id', 'id');
        $app['config']->set('zapmizer.partner.secret', 'secret');
        $app['config']->set('zapmizer.http.transport', LaravelHttpTransport::class);
    }

    protected function instanceClient(): InstanceClient
    {
        return app(InstanceClient::class, ['api_token' => 'tok', 'api_version' => '2025-06-27']);
    }

    public function testC3PartnerCallGoesThroughTheFake()
    {
        Http::fake(['zap.test/*' => Http::response(['url' => 'http://zap.test/c/1', 'expires_at' => null], 201)]);

        $session = app(PartnerClient::class)->createSession('https://app.test/cb', 'state-1');

        $this->assertSame('http://zap.test/c/1', $session->url);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://zap.test/api/connect/sessions'
            && $request->header('X-Partner-Key')[0] === 'id|secret'
            && $request->header('Accept')[0] === 'application/json');
    }

    public function testC3InstanceCallCarriesTokenAndVersion()
    {
        Http::fake(['zap.test/*' => Http::response(['data' => ['id' => 9, 'state' => 'connected', 'state_label' => 'Conectado', 'is_online' => true, 'is_up' => true]], 200)]);

        $this->instanceClient()->connection(9);

        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Bearer tok'
            && $request->header('api-version')[0] === '2025-06-27');
    }

    public function testMediaThroughTheFakeForEveryStatus()
    {
        Http::fakeSequence('zap.test/*')
            ->push('bytes', 200, ['Content-Type' => 'image/png'])
            ->push(['media_state' => 'downloading'], 202)
            ->push(['media_state' => 'unavailable'], 404)
            ->push(['message' => 'bad'], 422)
            ->push('', 429, ['Retry-After' => '7']);

        $client = $this->instanceClient();

        $this->assertSame('bytes', stream_get_contents($client->media(9, 'A', 1700000000)->stream()));
        $this->assertFalse($client->media(9, 'A', 1700000000)->isAttached());
        $this->assertFalse($client->media(9, 'A', 1700000000)->isAttached());

        try {
            $client->media(9, 'A', 1700000000);
            $this->fail('expected MediaRejectedException');
        } catch (MediaRejectedException $exception) {
            $this->assertStringContainsString('bad', $exception->reason());
        }

        try {
            $client->media(9, 'A', 1700000000);
            $this->fail('expected MediaRateLimitedException');
        } catch (MediaRateLimitedException $exception) {
            $this->assertSame(7, $exception->retryAfter());
        }
    }

    public function testTheSameFakedMediaCanBeFetchedTwice()
    {
        Http::fake(['zap.test/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);

        $client = $this->instanceClient();

        $this->assertSame('bytes', stream_get_contents($client->media(9, 'A', 1700000000)->stream()));
        $this->assertSame('bytes', stream_get_contents($client->media(9, 'A', 1700000000)->stream()));
    }

    public function testTheSameFakedAnswerCanBeFetchedTwiceWithoutATemporaryFile()
    {
        Http::fake(['zap.test/*' => Http::response(['media_state' => 'downloading'], 202)]);

        $client = new class ('tok', null, 'http://zap.test/api/', '2025-06-27', new LaravelHttpTransport()) extends InstanceClient {
            protected function temporaryMediaPath(): ?string
            {
                return null;
            }
        };

        $this->assertFalse($client->media(9, 'A', 1700000000)->isAttached());
        $this->assertFalse($client->media(9, 'A', 1700000000)->isAttached());
    }

    public function testC25GlobalMiddlewareReadingTheBodyDoesNotEmptyTheMedia()
    {
        if (!method_exists(Factory::class, 'globalMiddleware')) {
            $this->markTestSkipped('globalMiddleware exists from Laravel 10 on.');
        }

        Http::globalMiddleware(fn (callable $handler) => fn ($request, array $options) => $handler($request, $options)->then(function ($response) {
            (string) $response->getBody();

            return $response;
        }));
        Http::fake(['zap.test/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);

        $this->assertSame('bytes', stream_get_contents($this->instanceClient()->media(9, 'A', 1700000000)->stream()));
    }

    public function testStrayMediaRequestPassesThroughAndLeavesNoFile()
    {
        if (!method_exists(Factory::class, 'preventStrayRequests')) {
            $this->markTestSkipped('preventStrayRequests exists from Laravel 9 on.');
        }

        Http::preventStrayRequests();
        Http::fake(['other.test/*' => Http::response('{}')]);
        $before = glob(sys_get_temp_dir() . '/zapmizer-media-*');

        $caught = null;

        try {
            $this->instanceClient()->media(9, 'A', 1700000000);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $caught);
        $this->assertSame($before, glob(sys_get_temp_dir() . '/zapmizer-media-*'));
    }
}
