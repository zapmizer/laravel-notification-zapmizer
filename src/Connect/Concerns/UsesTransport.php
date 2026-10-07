<?php

namespace NotificationChannels\Zapmizer\Connect\Concerns;

use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Contracts\Transport;

trait UsesTransport
{
    protected function currentTransport(): Transport
    {
        $transport = $this->transport ?? null;

        if ($transport === null) {
            return new GuzzleTransport($this->http);
        }

        if ($transport instanceof GuzzleTransport && isset($this->http) && $transport->client() !== $this->http) {
            return $transport->withClient($this->http);
        }

        return $transport;
    }
}
