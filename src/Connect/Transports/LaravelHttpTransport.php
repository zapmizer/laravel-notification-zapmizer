<?php

namespace NotificationChannels\Zapmizer\Connect\Transports;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class LaravelHttpTransport implements Transport
{
    use AppliesDefaultTimeouts;

    public function __construct(protected ?float $connectTimeout = null, protected ?float $timeout = null)
    {
    }

    public function send(string $method, string $url, array $options = []): ResponseInterface
    {
        $factory = Http::getFacadeRoot();

        if ($factory === null) {
            throw new RuntimeException('LaravelHttpTransport needs a Laravel application.');
        }

        try {
            return $factory->send($method, $url, $this->prepareOptions($options))->toPsrResponse();
        } catch (HttpClientException|GuzzleException $exception) {
            throw ZapmizerUnavailableException::dueTo($exception);
        }
    }
}
