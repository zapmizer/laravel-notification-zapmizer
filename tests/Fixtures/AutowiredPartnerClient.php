<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Contracts\Transport;

class AutowiredPartnerClient extends PartnerClient
{
    public function transport(): Transport
    {
        return $this->transport;
    }
}
