<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Connect\MediaDownload;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\Team;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Zapmizer;
use NotificationChannels\Zapmizer\ZapmizerMessage;

class ConnectableTest extends TestCase
{
    use CreatesConnectionTables;

    /** @var array<int, array{request: \GuzzleHttp\Psr7\Request}> */
    protected array $history = [];

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.base_uri', 'http://localhost/api/');
        $app['config']->set('zapmizer.api_version', '2025-06-27');
    }

    protected function fakeHttp(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $this->instance(HttpClient::class, new HttpClient(['handler' => $stack]));
    }

    public function testMigrationCreatesTableWithUniqueConnectable()
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('zapmizer_connections'));

        $team = $this->connectedTeam();

        $this->expectException(\Illuminate\Database\QueryException::class);
        ZapmizerConnection::create(['connectable_type' => $team->getMorphClass(), 'connectable_id' => $team->id]);
    }

    public function testMigrationKeepsOneConnectablePerZapmizerTeam()
    {
        $this->connectedTeam(overrides: ['zapmizer_team_id' => 7]);
        // Unauthorized rows (no team yet) do not collide with each other.
        $this->connectedTeam(number: '5581922220000', overrides: ['zapmizer_team_id' => null]);
        $this->connectedTeam(number: '5581933330000', overrides: ['zapmizer_team_id' => null]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->connectedTeam(number: '5581944440000', overrides: ['zapmizer_team_id' => 7]);
    }

    public function testCredentialsAreEncryptedAtRestAndHiddenFromSerialization()
    {
        $team = $this->connectedTeam('whsec_abcdef', overrides: ['api_token' => 'token-secret-1234']);
        $connection = $team->zapmizerConnection;

        $raw = DB::table('zapmizer_connections')->where('id', $connection->id)->first();

        // Encrypted in the database...
        $this->assertNotEquals('token-secret-1234', $raw->api_token);
        $this->assertNotEquals('whsec_abcdef', $raw->webhook_secret);
        // ...and in the clear on the model. This is the leg that breaks if
        // someone puts an Attribute accessor over api_token: the accessor
        // wins over the cast and the ciphertext comes back.
        $this->assertEquals('token-secret-1234', $connection->api_token);
        $this->assertEquals('whsec_abcdef', $connection->webhook_secret);

        $serialized = $connection->fresh()->toArray();

        $this->assertArrayNotHasKey('api_token', $serialized);
        $this->assertArrayNotHasKey('webhook_secret', $serialized);
        $this->assertArrayNotHasKey('webhook_previous_secret', $serialized);
        $this->assertEquals('••••1234', $serialized['api_token_masked']);
        $this->assertEquals('5581911110000', $serialized['phone_number']);
        $this->assertEquals('Acme on Zapmizer', $serialized['zapmizer_team_name']);
        $this->assertTrue($serialized['is_active']);
    }

    public function testSigningSecretsOfferThePreviousOneDuringRotation()
    {
        $team = $this->connectedTeam('new', overrides: ['webhook_previous_secret' => 'old']);

        $this->assertEquals(['new', 'old'], $team->zapmizerConnection->signingSecrets());
    }

    public function testHasActiveZapmizer()
    {
        $this->assertFalse(Team::create(['name' => 'none'])->hasActiveZapmizer());
        $this->assertTrue($this->connectedTeam()->hasActiveZapmizer());
        $this->assertFalse($this->connectedTeam(number: '5581922220000', overrides: ['is_active' => false])->hasActiveZapmizer());
        $this->assertFalse($this->connectedTeam(number: '5581933330000', overrides: ['phone_number' => null])->hasActiveZapmizer());
    }

    public function testZapmizerClientCarriesTheConnectionToken()
    {
        $team = $this->connectedTeam(overrides: ['api_token' => 'team-token']);

        $client = $team->zapmizer();

        $this->assertInstanceOf(Zapmizer::class, $client);
        $this->assertEquals('team-token', $client->getToken());
    }

    public function testZapmizerMessageSendsFromThePairedNumber()
    {
        $this->fakeHttp(new Response(200, [], '{"id": 1}'));

        $team = $this->connectedTeam(number: '+55 (81) 91111-0000', overrides: ['api_token' => 'team-token']);

        $message = $team->zapmizerMessage('+55 81 99999-8888');

        $this->assertInstanceOf(ZapmizerMessage::class, $message);
        $this->assertEquals('5581911110000', $message->from);
        $this->assertEquals('5581999998888', $message->to);

        $message->text('hi')->send();

        $request = $this->history[0]['request'];
        $this->assertEquals('http://localhost/api/messages', (string) $request->getUri());
        $this->assertEquals('Bearer team-token', $request->getHeaderLine('Authorization'));
        $this->assertEquals('2025-06-27', $request->getHeaderLine('api-version'));

        parse_str((string) $request->getBody(), $form);
        $this->assertEquals('5581911110000', $form['from']);
        $this->assertEquals('5581999998888', $form['to']);
        $this->assertEquals('chat', $form['type']);
        $this->assertEquals('hi', $form['metadata']['text']);
    }

    public function testZapmizerMessageThrowsWhenNotConnected()
    {
        $this->expectException(ZapmizerConnectException::class);

        Team::create(['name' => 'none'])->zapmizerMessage('5581999998888');
    }

    public function testZapmizerMessageThrowsWhenAuthorizedButNotPaired()
    {
        $team = $this->connectedTeam(overrides: ['phone_number' => null, 'is_active' => false, 'connected_at' => null]);

        $this->expectException(ZapmizerConnectException::class);

        $team->zapmizerMessage('5581999998888');
    }

    public function testRegisterWebhookStoresTheSecretOnce()
    {
        $this->fakeHttp(new Response(201, [], json_encode(['data' => ['id' => 42, 'url' => 'x', 'secret' => 'whsec_new']])));

        $team = $this->connectedTeam(overrides: ['webhook_id' => null, 'webhook_secret' => null]);
        $connection = $team->zapmizerConnection;

        $registration = $connection->registerWebhook();

        $this->assertEquals(42, $registration->id);
        $this->assertEquals(42, $connection->fresh()->webhook_id);
        $this->assertEquals('whsec_new', $connection->fresh()->webhook_secret);

        $request = $this->history[0]['request'];
        $this->assertEquals('http://localhost/api/webhooks', (string) $request->getUri());
        $this->assertEquals(
            ['url' => 'http://localhost/zapmizer/webhook', 'enabled' => true],
            json_decode((string) $request->getBody(), true)
        );

        // Already registered: nothing is sent — Zapmizer would hand out a
        // secret that orphans the current one.
        $this->assertNull($connection->fresh()->registerWebhook());
        $this->assertCount(1, $this->history);
    }

    public function testConcurrentRegistrationSeesTheSecretOfTheFirst()
    {
        $this->fakeHttp(new Response(201, [], json_encode(['data' => ['id' => 42, 'secret' => 'whsec_new']])));

        $team = $this->connectedTeam(overrides: ['webhook_id' => null, 'webhook_secret' => null]);

        // Two in-memory copies of the same row — what `instance()` and the
        // `connection` poll hold when they overlap. Both see no secret.
        $first = $team->zapmizerConnection;
        $second = ZapmizerConnection::find($first->id);
        $this->assertNull($second->webhook_secret);

        $this->assertEquals('whsec_new', $first->registerWebhook()->secret);

        // The second decides on the locked, fresh row: no second POST, and it
        // ends up with the secret the first one stored.
        $this->assertNull($second->registerWebhook());
        $this->assertCount(1, $this->history);
        $this->assertEquals('whsec_new', $second->webhook_secret);
        $this->assertEquals(42, $second->webhook_id);
        $this->assertFalse($second->isDirty());
    }

    public function testRegisterWebhookRequiresASavedConnection()
    {
        $this->expectException(ZapmizerConnectException::class);

        Team::create(['name' => 'new'])->zapmizerConnectionOrNew()->registerWebhook();
    }

    public function testSerializationSurvivesAnUndecipherableToken()
    {
        $team = $this->connectedTeam('whsec_abcdef', overrides: ['api_token' => 'token-secret-1234']);
        $connection = $team->zapmizerConnection;

        // What is left of a row written with another APP_KEY.
        DB::table('zapmizer_connections')->where('id', $connection->id)->update(['api_token' => 'written-with-another-key']);
        $connection = $connection->fresh();

        $serialized = $connection->toArray();
        $this->assertEquals('••••', $serialized['api_token_masked']);
        $this->assertArrayNotHasKey('api_token', $serialized);
        $this->assertJson(json_encode($connection));

        // Connected: there IS a token — reading it is what fails, loudly, at
        // send time. The screen must not say "disconnected" because of it.
        $this->assertTrue($connection->hasApiToken());
        $this->assertTrue($connection->isConnected());
        $this->assertTrue($team->fresh()->hasActiveZapmizer());

        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $connection->api_token;
    }

    public function testMaskedTokenIsNullWithoutAToken()
    {
        $connection = $this->connectedTeam(overrides: ['api_token' => null])->zapmizerConnection;

        $this->assertNull($connection->toArray()['api_token_masked']);
        $this->assertFalse($connection->hasApiToken());
        $this->assertFalse($connection->isConnected());
    }

    public function testInstanceClientRequiresTheConnectionToken()
    {
        config()->set('zapmizer.api_token', 'single-tenant-token');
        $connection = $this->connectedTeam(overrides: ['api_token' => null])->zapmizerConnection;

        // Never the single-tenant token in a connection's name.
        $this->expectException(ZapmizerUnauthorizedException::class);
        $connection->instanceClient();
    }

    public function testDeleteRemoteWebhook()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['message' => 'Webhook removido.'])));

        $this->assertFalse($this->connectedTeam(overrides: ['webhook_id' => null])->zapmizerConnection->deleteRemoteWebhook());
        $this->assertCount(0, $this->history);

        $this->assertTrue($this->connectedTeam(number: '5581922220000')->zapmizerConnection->deleteRemoteWebhook());
        $this->assertEquals('http://localhost/api/webhooks/11', (string) $this->history[0]['request']->getUri());
    }

    public function testRotateWebhookSecretKeepsBoth()
    {
        $this->fakeHttp(new Response(200, [], json_encode(['data' => ['id' => 11, 'secret' => 'whsec_new', 'previous_secret' => 'whsec_test']])));

        $connection = $this->connectedTeam('whsec_test')->zapmizerConnection;

        $registration = $connection->rotateWebhookSecret();

        $this->assertEquals('whsec_new', $registration->secret);
        $this->assertEquals('whsec_new', $connection->fresh()->webhook_secret);
        $this->assertEquals('whsec_test', $connection->fresh()->webhook_previous_secret);
        $this->assertEquals(['whsec_new', 'whsec_test'], $connection->fresh()->signingSecrets());

        $request = $this->history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://localhost/api/webhooks/11/secret', (string) $request->getUri());
        $this->assertEquals('Bearer token-5581911110000', $request->getHeaderLine('Authorization'));
    }

    public function testRotateWithoutRegisteredWebhookThrows()
    {
        $connection = $this->connectedTeam(overrides: ['webhook_id' => null])->zapmizerConnection;

        $this->expectException(ZapmizerConnectException::class);

        $connection->rotateWebhookSecret();
    }

    public function testConnectableRelationPointsBack()
    {
        $team = $this->connectedTeam();

        $this->assertTrue(ZapmizerConnection::first()->connectable->is($team));
    }

    protected function inboundMessage(): InboundMessage
    {
        return InboundMessage::fromMessage([
            'id' => ['_serialized' => 'true_5581999998888@c.us_3EB0ABC123', 'id' => '3EB0ABC123'],
            'from' => '5581999998888@c.us',
            'type' => 'image',
            'hasMedia' => true,
            'timestamp' => 1700000000,
            '_data' => ['mimetype' => 'image/png'],
        ], '5581911110000@c.us');
    }

    public function testMediaAsksForTheConnectionsInstanceAndTheMessage()
    {
        $this->fakeHttp(new Response(200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'attachment; filename="3EB0ABC123.png"'], 'png-bytes'));
        $connection = $this->connectedTeam(overrides: ['api_token' => 'team-token', 'bot_instance_id' => 77])->zapmizerConnection;

        $download = $connection->media($this->inboundMessage());

        $this->assertTrue($download->isAttached());
        $this->assertEquals('png-bytes', $download->contents());
        $request = $this->history[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertEquals(['bot_instance_id' => '77', 'message_id' => 'true_5581999998888@c.us_3EB0ABC123', 'timestamp' => '1700000000'], $query);
        $this->assertEquals('Bearer team-token', $request->getHeaderLine('Authorization'));
    }

    public function testMediaRequiresAPairedInstance()
    {
        $connection = $this->connectedTeam(overrides: ['bot_instance_id' => null])->zapmizerConnection;

        $this->expectException(ZapmizerUnauthorizedException::class);
        $connection->media($this->inboundMessage());
    }

    public function testAwaitMediaRetriesWhileDownloading()
    {
        $this->fakeHttp(
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'downloading'])),
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'downloading'])),
            new Response(200, ['Content-Type' => 'image/png'], 'png-bytes'),
        );
        $slept = [];

        $download = $this->connectedTeam()->zapmizerConnection->awaitMedia(
            $this->inboundMessage(),
            sleep: function (int|float $seconds) use (&$slept) { $slept[] = $seconds; },
        );

        $this->assertTrue($download->isAttached());
        $this->assertCount(3, $this->history);
        $this->assertEquals([2, 5], $slept);
    }

    public function testAwaitMediaGivesUpWhenTheWaitsRunOut()
    {
        $this->fakeHttp(
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'downloading'])),
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'downloading'])),
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'downloading'])),
        );
        $slept = [];

        $download = $this->connectedTeam()->zapmizerConnection->awaitMedia(
            $this->inboundMessage(),
            waitsSeconds: [1, 3],
            sleep: function (int|float $seconds) use (&$slept) { $slept[] = $seconds; },
        );

        $this->assertTrue($download->isDownloading());
        $this->assertCount(3, $this->history);
        $this->assertEquals([1, 3], $slept);
    }

    public function testAwaitMediaStopsAtUnavailable()
    {
        $this->fakeHttp(
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'downloading'])),
            new Response(404, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'unavailable'])),
            new Response(200, ['Content-Type' => 'image/png'], 'never asked'),
        );
        $slept = [];

        $download = $this->connectedTeam()->zapmizerConnection->awaitMedia(
            $this->inboundMessage(),
            sleep: function (int|float $seconds) use (&$slept) { $slept[] = $seconds; },
        );

        $this->assertInstanceOf(MediaDownload::class, $download);
        $this->assertTrue($download->isUnavailable());
        $this->assertCount(2, $this->history);
        $this->assertEquals([2], $slept);
    }
}
