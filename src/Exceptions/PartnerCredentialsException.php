<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use NotificationChannels\Zapmizer\Support\ApiError;

/**
 * Class PartnerCredentialsException.
 *
 * Zapmizer refused the partner key (401/403): wrong, revoked or missing
 * from the config. A configuration defect on our side, not the user's. The
 * connect routes answer 503 `partner_unauthorized` and nothing else;
 * Zapmizer's message (which may carry a stack trace when debug is on over
 * there) stays in the log.
 */
final class PartnerCredentialsException extends ZapmizerApiException
{
    public function __construct(ApiError $error)
    {
        parent::__construct($error, 'Zapmizer refused the partner credentials.');
    }
}
