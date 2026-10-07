<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use NotificationChannels\Zapmizer\Support\ApiError;
use Throwable;

class ZapmizerApiException extends ZapmizerConnectException
{
    protected ApiError $apiError;

    public function __construct(ApiError $error, ?string $message = null, ?Throwable $previous = null)
    {
        $this->apiError = $error;

        parent::__construct($message ?? static::defaultMessage($error), 0, $previous);
    }

    protected static function defaultMessage(ApiError $error): string
    {
        $reason = rtrim($error->reason(), '.');

        return "Zapmizer refused the request (HTTP {$error->status}"
            . ($error->error !== null ? ", {$error->error}" : '')
            . ')'
            . ($reason !== '' ? ": {$reason}" : '')
            . '.';
    }

    public function status(): int
    {
        return $this->apiError->status;
    }

    public function error(): ?string
    {
        return $this->apiError->error;
    }

    public function reason(): string
    {
        return $this->apiError->reason();
    }

    /**
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        return $this->apiError->errors;
    }

    public function payload(): array
    {
        return $this->apiError->payload;
    }

    public function apiError(): ApiError
    {
        return $this->apiError;
    }
}
