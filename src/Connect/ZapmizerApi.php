<?php

namespace NotificationChannels\Zapmizer\Connect;

use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Support\ApiError;
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

    public static function failure(ResponseInterface|ApiError $failure): ZapmizerApiException
    {
        $error = $failure instanceof ApiError ? $failure : ApiError::from($failure);

        return match ($error->status) {
            401 => new ZapmizerUnauthorizedException($error, 'Zapmizer refused the connection token.'),
            429 => new ZapmizerRateLimitedException($error),
            default => new ZapmizerApiException($error),
        };
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
