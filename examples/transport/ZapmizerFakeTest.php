<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use Tests\TestCase;

class ZapmizerFakeTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('zapmizer.base_uri', 'https://zapmizer.test/api/');
        $app['config']->set('zapmizer.partner.id', 'partner-id');
        $app['config']->set('zapmizer.partner.secret', 'partner-secret');

        // In your app this is the `http.transport` key of config/zapmizer.php.
        // With the default GuzzleTransport, Http::fake() would not see these calls.
        $app['config']->set('zapmizer.http.transport', LaravelHttpTransport::class);
    }

    public function testConnectSessionIsFaked()
    {
        Http::fake(['zapmizer.test/*' => Http::response(['url' => 'https://zapmizer.test/connect/1', 'expires_at' => null], 201)]);

        $session = app(PartnerClient::class)->createSession('https://app.test/zapmizer/callback', 'state-123');

        $this->assertSame('https://zapmizer.test/connect/1', $session->url);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://zapmizer.test/api/connect/sessions'
            && $request['state'] === 'state-123');
    }

    public function testMediaDownloadIsFaked()
    {
        Http::fake(['zapmizer.test/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])]);

        $client = app(InstanceClient::class, ['api_token' => 'team-token', 'api_version' => null]);
        $download = $client->media(77, 'true_5581999998888@c.us_3EB0ABC123', 1700000000);

        $this->assertTrue($download->isAttached());
        $this->assertSame('png-bytes', $download->contents());
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://zapmizer.test/api/whatsapp-messages/media')
            && $request->hasHeader('Authorization', 'Bearer team-token')
            && str_contains($request->url(), 'bot_instance_id=77'));
    }
}
