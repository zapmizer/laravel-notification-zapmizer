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
use NotificationChannels\Zapmizer\Exceptions\InstanceBootingException;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\InstancePlanLimitException;
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

    public function testInstancesListsConnectedOnesUnpaginated()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode(['data' => [['id' => 1, 'client' => ['cid_formatted' => '+55 81 91111-0000']]]])),
        ]));

        $instances = $client->instances(connected: true);

        $this->assertCount(1, $instances);
        $this->assertEquals(1, $instances[0]['id']);

        $request = $this->history[0]['request'];
        $this->assertEquals('http://localhost/api/bot-instances?connected=1&per_page=100', (string) $request->getUri());
        $this->assertEquals('Bearer team-token', $request->getHeaderLine('Authorization'));
        $this->assertEquals('2025-06-27', $request->getHeaderLine('api-version'));
    }

    public function testCreateInstanceMapsPlanLimitAndBooting()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(402, [], json_encode(['message' => 'Plan limit reached.'])),
            new Response(423, [], ''),
            new Response(201, [], json_encode(['data' => ['id' => 9]])),
        ]));

        try {
            $client->createInstance();
            $this->fail('Expected InstancePlanLimitException.');
        } catch (InstancePlanLimitException $exception) {
            $this->assertEquals('Plan limit reached.', $exception->getMessage());
        }

        try {
            $client->createInstance();
            $this->fail('Expected InstanceBootingException.');
        } catch (InstanceBootingException) {
            $this->addToAssertionCount(1);
        }

        $this->assertEquals(9, $client->createInstance()['id']);
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

    public function testCreateInstanceCarriesTheBootingIdFromA423()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(423, [], json_encode(['message' => 'Already booting.', 'bot_instance_id' => 77])),
        ]));

        try {
            $client->createInstance();
            $this->fail('Expected InstanceBootingException.');
        } catch (InstanceBootingException $exception) {
            $this->assertEquals(77, $exception->instanceId);
        }
    }

    public function testCreateInstanceRebootsAnExistingOneById()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode(['data' => ['id' => 9]])),
            // Re-booting an unknown id: Zapmizer answers 423 without an id.
            new Response(423, [], json_encode(['message' => 'Conta do WhatsApp não encontrada.'])),
        ]));

        $this->assertEquals(9, $client->createInstance(9)['id']);
        $this->assertEquals(['bot_instance_id' => 9], json_decode((string) $this->history[0]['request']->getBody(), true));

        $this->expectException(InstanceGoneException::class);
        $client->createInstance(9);
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
}
