<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;

class ClientTransportTest extends TestCase
{
    protected array $history = [];

    protected function guzzle(array $queue): HttpClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new HttpClient(['handler' => $stack]);
    }

    protected function connectionJson(): string
    {
        return json_encode(['data' => ['id' => 9, 'state' => 'connected', 'state_label' => 'Conectado', 'is_online' => true, 'is_up' => true, 'number' => '5581911110000']]);
    }

    public function testTransportArgumentWinsOverTheGuzzleArgument()
    {
        $transport = new RecordingTransport(new Response(201, [], json_encode(['url' => 'http://zap.test/c/1', 'expires_at' => null])));
        $client = new PartnerClient('id', 'secret', $this->guzzle([]), 'http://zap.test/api/', $transport);

        $client->createSession('https://app.test/cb', 'state-1');

        $this->assertSame('http://zap.test/api/connect/sessions', $transport->calls[0]['url']);
        $this->assertSame('id|secret', $transport->calls[0]['options']['headers']['X-Partner-Key']);
        $this->assertSame('application/json', $transport->calls[0]['options']['headers']['Accept']);
        $this->assertCount(0, $this->history);
    }

    public function testC11GuzzleArgumentStillWorksWithoutTransport()
    {
        $client = new InstanceClient('tok', $this->guzzle([new Response(200, [], $this->connectionJson())]), 'http://zap.test/api', '2025-06-27');

        $client->connection(9);

        $this->assertSame('Bearer tok', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('2025-06-27', $this->history[0]['request']->getHeaderLine('api-version'));
    }

    public function testApiVersionNullEmptyOrZeroIsNotSent()
    {
        foreach ([null, '', '0'] as $version) {
            $transport = new RecordingTransport(new Response(200, [], $this->connectionJson()));

            (new InstanceClient('tok', null, 'http://zap.test/api', $version, $transport))->connection(9);

            $this->assertArrayNotHasKey('api-version', $transport->calls[0]['options']['headers']);
        }
    }

    public function testC9SubclassSwappingHttpAfterConstructionUsesItWithTheTransportTimeouts()
    {
        $client = new class ('tok', null, 'http://zap.test/api', null, new GuzzleTransport(null, 5.0, 10.0)) extends InstanceClient {
            public function swap(HttpClient $http): void
            {
                $this->http = $http;
            }
        };
        $client->swap($this->guzzle([new Response(200, [], $this->connectionJson())]));

        $client->connection(9);

        $this->assertCount(1, $this->history);
        $this->assertSame(10.0, $this->history[0]['options']['timeout']);
    }

    public function testC8SubclassWithoutParentConstructorFallsBackToItsHttp()
    {
        $http = $this->guzzle([new Response(200, [], $this->connectionJson())]);
        $client = new class ($http) extends InstanceClient {
            public function __construct(HttpClient $http)
            {
                $this->http = $http;
                $this->apiBaseUri = 'http://zap.test/api';
                $this->token = 'tok';
                $this->apiVersion = null;
            }
        };

        $client->connection(9);

        $this->assertSame('http://zap.test/api/bot-instances/9/connection', (string) $this->history[0]['request']->getUri());
    }

    public function testSubclassChangingBaseUriAndTokenAfterConstructionIsHonoured()
    {
        $transport = new RecordingTransport(new Response(200, [], $this->connectionJson()));
        $client = new class ('old', null, 'http://old.test/api', null, $transport) extends InstanceClient {
            public function retarget(): void
            {
                $this->apiBaseUri = 'http://new.test/api';
                $this->token = 'new';
            }
        };
        $client->retarget();

        $client->connection(9);

        $this->assertSame('http://new.test/api/bot-instances/9/connection', $transport->calls[0]['url']);
        $this->assertSame('Bearer new', $transport->calls[0]['options']['headers']['Authorization']);
    }

    public function testC23CallHeaderCannotOverrideAuthentication()
    {
        $transport = new RecordingTransport(new Response(200, [], '{}'));
        $client = new class ('tok', null, 'http://zap.test/api', null, $transport) extends InstanceClient {
            public function raw(): void
            {
                $this->request('GET', '/x', ['headers' => ['authorization' => 'Bearer evil']]);
            }
        };

        $client->raw();

        $this->assertSame(['Accept' => 'application/json', 'Authorization' => 'Bearer tok'], $transport->calls[0]['options']['headers']);
    }
}
