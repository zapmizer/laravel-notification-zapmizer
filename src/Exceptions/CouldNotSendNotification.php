<?php

namespace NotificationChannels\Zapmizer\Exceptions;

use Exception;
use JsonException;
use GuzzleHttp\Exception\ClientException;

/**
 * Class CouldNotSendNotification.
 */
final class CouldNotSendNotification extends Exception
{
    /**
     * Thrown when there's a bad request and an error is responded.
     *
     *
     * @throws JsonException
     */
    public static function zapmizerRespondedWithAnError(ClientException $exception): self
    {
        if (!$exception->hasResponse()) {
            return new self('Zapmizer responded with an error but no response body found');
        }

        $statusCode = $exception->getResponse()->getStatusCode();

        $result = json_decode($exception->getResponse()->getBody()->getContents(), false, 512);
        $description = $result->description ?? $exception->getResponse()->getBody();

        return new self("Zapmizer responded with an error `{$statusCode} - {$description}`", 0, $exception);
    }

    /**
     * Thrown when there's no bot token provided.
     */
    public static function zapmizerBotTokenNotProvided(string $message): self
    {
        return new self($message);
    }

    /**
     * Thrown when we're unable to communicate with Zapmizer.
     */
    public static function couldNotCommunicateWithZapmizer(string $message): self
    {
        return new self("The communication with Zapmizer failed. `{$message}`");
    }
}
