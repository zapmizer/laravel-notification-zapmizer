<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Contracts\Transport;
use Psr\Http\Message\ResponseInterface;

class RecordingTransport implements Transport
{
    public array $calls = [];

    protected array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return array_shift($this->responses);
    }
}
