<?php

namespace NotificationChannels\Zapmizer\Connect\Transports;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class LaravelHttpTransport implements Transport
{
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
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (HttpClientException|GuzzleException $exception) {
            throw ZapmizerUnavailableException::dueTo($exception);
        }
    }

    protected function prepareOptions(array $options): array
    {
        return array_replace(
            array_filter(
                ['connect_timeout' => $this->connectTimeout, 'timeout' => $this->timeout],
                fn ($value) => $value !== null,
            ),
            $options,
            ['http_errors' => false, 'allow_redirects' => false],
        );
    }
}
