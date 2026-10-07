<?php

namespace NotificationChannels\Zapmizer\Connect\Transports;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use Psr\Http\Message\ResponseInterface;

class GuzzleTransport implements Transport
{
    use AppliesDefaultTimeouts;

    protected Client $client;

    public function __construct(?Client $client = null, protected ?float $connectTimeout = null, protected ?float $timeout = null)
    {
        $this->client = $client ?? new Client();
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function send(string $method, string $url, array $options = []): ResponseInterface
    {
        try {
            return $this->client->request($method, $url, $this->prepareOptions($options));
        } catch (GuzzleException $exception) {
            throw ZapmizerUnavailableException::dueTo($exception);
        }
    }
}
