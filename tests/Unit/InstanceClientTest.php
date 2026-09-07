<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\InstanceConnection;
use NotificationChannels\Zapmizer\Connect\MediaDownload;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\TestCase;

class InstanceClientTest extends TestCase
{
    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): InstanceClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new InstanceClient('team-token', new HttpClient(['handler' => $stack]), 'http://localhost/api', '2025-06-27');
    }

    public function testConnectionMapsStates()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode(['data' => [
                'id' => 9, 'state' => 'connected', 'state_label' => 'Conectado', 'is_online' => true, 'is_up' => true,
                'qrcode' => null, 'qrcode_available_at' => null, 'qrcode_expires_at' => null, 'number' => '5581911110000',
            ]])),
            new Response(404, [], '{}'),
            new Response(401, [], '{}'),
            new Response(503, [], ''),
        ]));

        $connection = $client->connection(9);

        $this->assertInstanceOf(InstanceConnection::class, $connection);
        $this->assertTrue($connection->isConnected());
        $this->assertEquals('5581911110000', $connection->number);
        $this->assertEquals('http://localhost/api/bot-instances/9/connection', (string) $this->history[0]['request']->getUri());
        $this->assertEquals('Bearer team-token', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertEquals('2025-06-27', $this->history[0]['request']->getHeaderLine('api-version'));

        $this->expectExceptionInOrder([InstanceGoneException::class, ZapmizerUnauthorizedException::class, ZapmizerUnavailableException::class], fn () => $client->connection(9));
    }

    public function testCreateWebhookReturnsTheSecretOnce()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode(['data' => ['id' => 42, 'secret' => 'whsec_x']])),
        ]));

        $registration = $client->createWebhook('http://app.test/zapmizer/webhook');

        $this->assertEquals(42, $registration->id);
        $this->assertEquals('whsec_x', $registration->secret);
        $this->assertNull($registration->previousSecret);
        $this->assertEquals(
            ['url' => 'http://app.test/zapmizer/webhook', 'enabled' => true],
            json_decode((string) $this->history[0]['request']->getBody(), true)
        );
    }

    public function testRotateWebhookSecretReturnsBoth()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode(['data' => ['id' => 42, 'secret' => 'new', 'previous_secret' => 'old']])),
        ]));

        $registration = $client->rotateWebhookSecret(42);

        $this->assertEquals(['new', 'old'], [$registration->secret, $registration->previousSecret]);
        $this->assertEquals('http://localhost/api/webhooks/42/secret', (string) $this->history[0]['request']->getUri());
    }

    public function testTokenIsMandatory()
    {
        // No silent fallback to the single-tenant token: resolving the client
        // from the container without one is a hard error.
        $this->expectException(ZapmizerUnauthorizedException::class);

        $this->app->make(InstanceClient::class);
    }

    public function testDeleteWebhookTreats404AsDone()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode(['message' => 'Webhook removido.'])),
            new Response(404, [], '{}'),
            new Response(403, [], '{}'),
        ]));

        $client->deleteWebhook(42);
        $client->deleteWebhook(42);
        $this->assertEquals('DELETE', $this->history[0]['request']->getMethod());
        $this->assertEquals('http://localhost/api/webhooks/42', (string) $this->history[0]['request']->getUri());

        $this->expectException(ZapmizerConnectException::class);
        $client->deleteWebhook(42);
    }

    public function testMediaAttachedIsSpooledToATemporaryFile()
    {
        $bytes = "\x89PNG\r\n\x1a\n" . random_bytes(2048);
        $client = $this->makeClient(new MockHandler([
            new Response(200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) strlen($bytes),
                'Content-Disposition' => 'attachment; filename="3EB0ABC123.png"',
                'Cache-Control' => 'private, no-store',
            ], $bytes),
        ]));

        $download = $client->media(9, 'true_5581999998888@c.us_3EB0ABC123', 1700000000);

        $this->assertInstanceOf(MediaDownload::class, $download);
        $this->assertTrue($download->isAttached());
        $this->assertEquals(MediaDownload::ATTACHED, $download->state);
        $this->assertEquals('image/png', $download->mimeType);
        $this->assertEquals('3EB0ABC123.png', $download->filename);
        $this->assertEquals(strlen($bytes), $download->size);

        $path = $download->path();
        $this->assertFileExists($path);
        $this->assertEquals($bytes, file_get_contents($path));
        $this->assertEquals($bytes, $download->contents());
        $this->assertEquals($bytes, stream_get_contents($download->stream()));

        $request = $this->history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http://localhost/api/whatsapp-messages/media', $request->getUri()->withQuery('')->__toString());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertEquals(['bot_instance_id' => '9', 'message_id' => 'true_5581999998888@c.us_3EB0ABC123', 'timestamp' => '1700000000'], $query);
        $this->assertEquals('Bearer team-token', $request->getHeaderLine('Authorization'));

        // The file is the object's: gone with it.
        unset($download);
        $this->assertFileDoesNotExist($path);
    }

    public function testMediaTimestampIsOptional()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/octet-stream'], 'bytes'),
        ]));

        $download = $client->media(9, 'ABC');

        $this->assertNull($download->filename);
        $this->assertNull($download->size);
        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertArrayNotHasKey('timestamp', $query);
    }

    public function testMediaDownloadingAndUnavailable()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'downloading'])),
            new Response(404, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'unavailable'])),
            // A 404 for someone else's instance carries no media_state.
            new Response(404, ['Content-Type' => 'application/json'], json_encode(['message' => 'Not found.'])),
        ]));

        $downloading = $client->media(9, 'ABC', 1700000000);
        $this->assertTrue($downloading->isDownloading());
        $this->assertFalse($downloading->isAttached());
        $this->assertNull($downloading->mimeType);

        $unavailable = $client->media(9, 'ABC', 1700000000);
        $this->assertTrue($unavailable->isUnavailable());

        $this->assertTrue($client->media(9, 'ABC', 1700000000)->isUnavailable());

        $this->expectException(\RuntimeException::class);
        $downloading->path();
    }

    public function testMediaRefusesTheOtherAnswersLikeEveryEndpoint()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(401, [], '{}'),
            new Response(302, ['Location' => 'http://localhost/login'], ''),
            new Response(403, ['Content-Type' => 'application/json'], '{}'),
            new Response(503, [], ''),
        ]));

        $this->expectExceptionInOrder([
            ZapmizerUnauthorizedException::class,
            ZapmizerConnectException::class,
            ZapmizerConnectException::class,
            ZapmizerUnavailableException::class,
        ], fn () => $client->media(9, 'ABC'));
    }

    public function testMediaUnexpectedJsonOnA202IsAnError()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(202, ['Content-Type' => 'text/html'], '<html>'),
        ]));

        $this->expectException(ZapmizerConnectException::class);
        $client->media(9, 'ABC');
    }

    public function testMediaFilenameVariants()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename*=UTF-8''extrato%20m%C3%AAs.pdf"], 'x'),
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename=plain.pdf'], 'x'),
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline'], 'x'),
        ]));

        $this->assertEquals('extrato mês.pdf', $client->media(9, 'A')->filename);
        $this->assertEquals('plain.pdf', $client->media(9, 'A')->filename);
        $this->assertNull($client->media(9, 'A')->filename);
    }

    /**
     * @param array<int, class-string<\Throwable>> $expected
     */
    protected function expectExceptionInOrder(array $expected, callable $call): void
    {
        foreach ($expected as $class) {
            try {
                $call();
                $this->fail("Expected {$class}.");
            } catch (\Throwable $exception) {
                $this->assertInstanceOf($class, $exception);
            }
        }
    }

    public function testARedirectIsRefusedInsteadOfFollowed()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(302, ['Location' => 'http://localhost/login'], ''),
        ]));

        try {
            $client->connection(9);
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertStringContainsString('302', $exception->getMessage());
        }

        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }
}
