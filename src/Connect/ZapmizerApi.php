<?php

namespace NotificationChannels\Zapmizer\Connect;

use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;

class ZapmizerApi
{
    public function __construct(protected Transport $transport)
    {
    }

    public function send(string $method, string $url, array $options = [], array $fixedHeaders = [], bool $expectsJson = true): ResponseInterface
    {
        $fixed = array_merge(['Accept' => 'application/json'], $fixedHeaders);
        $options['headers'] = array_merge($this->withoutHeaders($options['headers'] ?? [], array_keys($fixed)), $fixed);

        $response = $this->transport->send($method, $url, $options);

        if ($response->getStatusCode() >= 500) {
            throw ZapmizerUnavailableException::dueTo();
        }

        if ($response->getStatusCode() < 400) {
            $problem = $expectsJson
                ? JsonResponse::problem($response, sniffBody: false)
                : JsonResponse::redirected($response);

            if ($problem !== null) {
                throw ZapmizerConnectException::unexpectedResponse($problem);
            }
        }

        return $response;
    }

    protected function withoutHeaders(array $headers, array $names): array
    {
        $names = array_map('strtolower', $names);

        return array_filter(
            $headers,
            fn ($name) => !in_array(strtolower((string) $name), $names, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
