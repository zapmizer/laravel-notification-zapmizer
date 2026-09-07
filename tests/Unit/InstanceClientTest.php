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
