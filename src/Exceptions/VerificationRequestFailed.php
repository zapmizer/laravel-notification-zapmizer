<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use GuzzleHttp\Exception\BadResponseException;

/**
 * Class VerificationRequestFailed.
 *
 * Thrown when Zapmizer responds with a 4xx/5xx status code.
 */
final class VerificationRequestFailed extends ZapmizerVerificationException
{
    protected int $statusCode = 0;

    protected string $responseBody = '';

    public static function fromResponse(BadResponseException $exception): self
    {
        $statusCode = $exception->getResponse()->getStatusCode();
        $body = (string) $exception->getResponse()->getBody();

        $result = json_decode($body, false);
        $description = $result->message ?? $result->description ?? $body;

        $instance = new self("Zapmizer responded with an error `{$statusCode} - {$description}`", 0, $exception);
        $instance->statusCode = $statusCode;
        $instance->responseBody = $body;

        return $instance;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
