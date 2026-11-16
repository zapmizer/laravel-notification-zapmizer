<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use NotificationChannels\Zapmizer\Support\ApiError;

class ZapmizerRateLimitedException extends ZapmizerApiException
{
    public function __construct(ApiError $error, ?string $message = null)
    {
        parent::__construct(
            $error,
            $message ?? 'Zapmizer rate-limited the request.' . ($error->retryAfter !== null ? " Retry in {$error->retryAfter} s." : ''),
        );
    }

    public function retryAfter(): ?int
    {
        return $this->apiError->retryAfter;
    }

    /**
     * @return array<string, int|string>
     */
    public function context(): array
    {
        return parent::context() + array_filter(['zapmizer_retry_after' => $this->retryAfter()], fn ($value) => $value !== null);
    }
}
