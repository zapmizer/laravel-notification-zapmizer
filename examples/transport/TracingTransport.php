<?php

namespace App\Zapmizer;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use Psr\Http\Message\ResponseInterface;

/**
 * Tags every Zapmizer request with an id and logs how it went.
 *
 * Enable it in config/zapmizer.php:
 *
 *     'http' => [
 *         'transport' => \App\Zapmizer\TracingTransport::class,
 *     ],
 *
 * It extends GuzzleTransport, which already follows the transport rules:
 * it honors `sink`, never follows redirects and does not throw on 4xx/5xx.
 * Only the options are touched here, and the response is returned as is.
 */
class TracingTransport extends GuzzleTransport
{
    public function send(string $method, string $url, array $options = []): ResponseInterface
    {
        $requestId = (string) Str::uuid();
        $options['headers'] = array_merge($options['headers'] ?? [], ['X-Request-Id' => $requestId]);

        try {
            $response = parent::send($method, $url, $options);
        } catch (ZapmizerUnavailableException $exception) {
            Log::warning('zapmizer request failed', ['request_id' => $requestId, 'method' => $method, 'url' => $url]);

            throw $exception;
        }

        Log::info('zapmizer request', [
            'request_id' => $requestId,
            'method' => $method,
            'url' => $url,
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
