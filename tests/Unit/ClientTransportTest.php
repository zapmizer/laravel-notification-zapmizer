<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Test\TestCase;

class ClientTransportTest extends TestCase
{
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
}
