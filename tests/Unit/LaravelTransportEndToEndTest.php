<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Zapmizer;
use RuntimeException;

class LaravelTransportEndToEndTest extends TestCase
{
    use AssertsContract;

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

    public function testP26TheFourPartnerCallsGoThroughTheFake()
    {
        $session = '{"url":"http://zap.test/connect/1","expires_at":"2026-09-07T01:00:00Z"}';
        $token = '{"token":"1|sanctum","user_id":3,"team_id":7,"team_name":"Acme","phone_number":"5581911110000","bot_instance_id":9,"webhook_id":null,"webhook_secret":null}';
        $subscription = '{"user_id":3,"team_id":7,"external_id":"42","subscribed":false,"quantity":null,"trial_ends_at":"2999-01-01T00:00:00Z","payment_incomplete":false}';
        $checkout = '{"url":"https://checkout.test/c/1","expires_at":null}';
        $this->assertMatchesContract('POST', '/connect/sessions', 201, $session);
        $this->assertMatchesContract('POST', '/connect/token', 200, $token);
        $this->assertMatchesContract('GET', '/partner/users/{externalId}', 200, $subscription);
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 200, $checkout);
        $json = ['Content-Type' => 'application/json'];
        Http::fake([
            'zap.test/api/connect/sessions' => Http::response($session, 201, $json),
            'zap.test/api/connect/token' => Http::response($token, 200, $json),
            'zap.test/api/partner/users/42/checkout' => Http::response($checkout, 200, $json),
            'zap.test/api/partner/users/42' => Http::response($subscription, 200, $json),
        ]);
        $client = app(PartnerClient::class);

        $this->assertSame('2026-09-07T01:00:00+00:00', $client->createSession('https://app.test/cb', 'state-1', externalId: '42')->expiresAt->toIso8601String());
        $connectToken = $client->exchangeCode('12.secret');
        $this->assertSame(3, $connectToken->userId);
        $this->assertSame(7, $connectToken->teamId);
        $this->assertTrue($connectToken->hasNumber());
        $this->assertTrue($client->subscription('42')->hasAccess());
        $this->assertSame('https://checkout.test/c/1', $client->checkout('42', 'https://app.test/billing', 'state-2')->url);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://zap.test/api/connect/sessions'
            && $request['external_id'] === '42'
            && $request['state'] === 'state-1');
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'http://zap.test/api/partner/users/42'
            && $request->header('X-Partner-Key')[0] === 'id|secret'
            && $request->header('Accept')[0] === 'application/json');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://zap.test/api/partner/users/42/checkout'
            && $request['redirect_uri'] === 'https://app.test/billing'
            && $request['state'] === 'state-2');
    }

    public function testP26PartnerRefusalsThroughTheFake()
    {
        $notFound = '{"message":"Not found."}';
        $conflict = '{"error":"already_subscribed","message":"Already subscribed."}';
        $this->assertMatchesContract('POST', '/connect/token', 404, $notFound);
        $this->assertMatchesContract('GET', '/partner/users/{externalId}', 404, $notFound);
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 409, $conflict);
        $json = ['Content-Type' => 'application/json'];
        Http::fake([
            'zap.test/api/connect/token' => Http::response($notFound, 404, $json),
            'zap.test/api/partner/users/42/checkout' => Http::response($conflict, 409, $json),
            'zap.test/api/partner/users/42' => Http::response($notFound, 404, $json),
        ]);
        $client = app(PartnerClient::class);

        $this->assertNull($client->exchangeCode('used'));
        $this->assertNull($client->subscription('42'));

        try {
            $client->checkout('42', 'https://app.test/billing');
            $this->fail('Expected ZapmizerApiException.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(409, $exception->status());
            $this->assertSame(ErrorCode::ALREADY_SUBSCRIBED, $exception->error());
        }
    }

    public function testM29TextSendIsAFormThroughTheFake()
    {
        $body = '{"id":1}';
        $this->assertMatchesContract('POST', '/messages', 200, $body);
        Http::fake(['zap.test/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        app(Zapmizer::class, ['api_token' => 'bot-token', 'api_version' => '2025-06-27'])
            ->sendMessage(['type' => 'chat', 'from' => '5581999990000', 'to' => '5511999999999', 'metadata' => ['text' => 'hi']]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://zap.test/api/messages'
            && $request->isForm()
            && $request['metadata']['text'] === 'hi'
            && $request->header('Authorization')[0] === 'Bearer bot-token'
            && $request->header('api-version')[0] === '2025-06-27');
    }

    public function testM28FileSendIsMultipartThroughTheFake()
    {
        $body = '{"id":1}';
        $this->assertMatchesContract('POST', '/messages', 200, $body);
        Http::fake(['zap.test/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        app(Zapmizer::class, ['api_token' => 'bot-token'])
            ->sendMessageWithFile(['type' => 'document', 'from' => '5581999990000', 'to' => '5511999999999'], __FILE__);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://zap.test/api/messages'
            && $request->isMultipart()
            && str_contains($request->body(), 'name="uploaded_media"')
            && str_contains($request->body(), 'class LaravelTransportEndToEndTest extends TestCase'));
    }

    public function testAFileWithNestedMetadataSendsTheNestedFieldThroughTheFake()
    {
        $body = '{"id":1}';
        $this->assertMatchesContract('POST', '/messages', 200, $body);
        Http::fake(['zap.test/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        app(Zapmizer::class, ['api_token' => 'bot-token'])
            ->sendMessageWithFile(['type' => 'image', 'text' => 'caption', 'metadata' => ['text' => 'hi']], __FILE__);

        Http::assertSent(fn (Request $request) => $request->isMultipart()
            && str_contains($request->body(), 'name="metadata[text]"'));
    }

    public function testAnEmptyFakeIsNotAnApiAnswer()
    {
        Http::fake();

        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('response body is not valid JSON');

        app(Zapmizer::class, ['api_token' => 'bot-token'])->sendMessage(['type' => 'chat', 'to' => '5511999999999']);
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

        $client = new class ('tok', new LaravelHttpTransport(), 'http://zap.test/api/', '2025-06-27') extends InstanceClient {
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
