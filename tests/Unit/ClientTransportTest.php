<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;

class ClientTransportTest extends TestCase
{
    protected function connectionJson(): string
    {
        return json_encode(['data' => ['id' => 9, 'state' => 'connected', 'state_label' => 'Conectado', 'is_online' => true, 'is_up' => true, 'number' => '5581911110000']]);
    }

    public function testPartnerCallGoesThroughTheTransport()
    {
        $transport = new RecordingTransport(new Response(201, [], json_encode(['url' => 'http://zap.test/c/1', 'expires_at' => null])));
        $client = new PartnerClient('id', 'secret', $transport, 'http://zap.test/api/');

        $client->createSession('https://app.test/cb', 'state-1');

        $this->assertSame('http://zap.test/api/connect/sessions', $transport->calls[0]['url']);
        $this->assertSame('id|secret', $transport->calls[0]['options']['headers']['X-Partner-Key']);
        $this->assertSame('application/json', $transport->calls[0]['options']['headers']['Accept']);
    }

    public function testWithoutTransportTheClientsUseGuzzle()
    {
        $partner = new class () extends PartnerClient {
            public function transport(): Transport
            {
                return $this->transport;
            }
        };
        $instance = new class ('tok') extends InstanceClient {
            public function transport(): Transport
            {
                return $this->transport;
            }
        };

        $this->assertInstanceOf(GuzzleTransport::class, $partner->transport());
        $this->assertInstanceOf(GuzzleTransport::class, $instance->transport());
    }

    public function testApiVersionNullEmptyOrZeroIsNotSent()
    {
        foreach ([null, '', '0'] as $version) {
            $transport = new RecordingTransport(new Response(200, [], $this->connectionJson()));

            (new InstanceClient('tok', $transport, 'http://zap.test/api', $version))->connection(9);

            $this->assertArrayNotHasKey('api-version', $transport->calls[0]['options']['headers']);
        }
    }

    public function testSubclassChangingBaseUriAndTokenAfterConstructionIsHonoured()
    {
        $transport = new RecordingTransport(new Response(200, [], $this->connectionJson()));
        $client = new class ('old', $transport, 'http://old.test/api') extends InstanceClient {
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
        $client = new class ('tok', $transport, 'http://zap.test/api') extends InstanceClient {
            public function raw(): void
            {
                $this->request('GET', '/x', ['headers' => ['authorization' => 'Bearer evil']]);
            }
        };

        $client->raw();

        $this->assertSame(['Accept' => 'application/json', 'Authorization' => 'Bearer tok'], $transport->calls[0]['options']['headers']);
    }
}
