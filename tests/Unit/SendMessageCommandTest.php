<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Test\TestCase;

class SendMessageCommandTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('zapmizer.base_uri', 'http://zap.test/api/');
        $app['config']->set('zapmizer.api_token', 'bot-token');
        $app['config']->set('zapmizer.http.transport', LaravelHttpTransport::class);
    }

    public function testCommandSendsTheMessage()
    {
        Http::fake(['zap.test/*' => Http::response('{"id":1}', 200, ['Content-Type' => 'application/json'])]);

        $this->artisan('zapmizer:send-message', ['message' => 'oi', '--from' => '5581999990000', '--to' => '5511999999999'])
            ->expectsOutput('Message sent!')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://zap.test/api/messages'
            && $request['type'] === 'chat'
            && $request['metadata']['text'] === 'oi'
            && $request['to'] === '5511999999999');
    }
}
