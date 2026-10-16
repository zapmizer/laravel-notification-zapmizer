<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Test\TestCase;

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
}
