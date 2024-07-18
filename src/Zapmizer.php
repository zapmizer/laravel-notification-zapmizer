<?php

namespace NotificationChannels\Zapmizer;

use Exception;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ClientException;
use NotificationChannels\Zapmizer\Exceptions\CouldNotSendNotification;

/**
 * Class Zapmizer.
 */
class Zapmizer
{
    /** @var HttpClient HTTP Client */
    protected HttpClient $http;

    /** @var null|string Zapmizer Bot API Token. */
    protected ?string $token;

    /** @var string Zapmizer Bot API Base URI */
    protected string $apiBaseUri;

    public function __construct(?string $token = null, ?HttpClient $httpClient = null, ?string $apiBaseUri = null)
    {
        $this->token = $token;
        $this->http = new HttpClient();
        $this->setApiBaseUri($apiBaseUri ?? 'https://app.zapmizer.com/api/');
    }

    /**
     * Token getter.
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Token setter.
     *
     * @return $this
     */
    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    /**
     * API Base URI getter.
     */
    public function getApiBaseUri(): string
    {
        return $this->apiBaseUri;
    }

    /**
     * API Base URI setter.
     *
     * @return $this
     */
    public function setApiBaseUri(string $apiBaseUri): self
    {
        $this->apiBaseUri = rtrim($apiBaseUri, '/');

        return $this;
    }

    /**
     * Set HTTP Client.
     *
     * @return $this
     */
    public function setHttpClient(HttpClient $http): self
    {
        $this->http = $http;

        return $this;
    }

    /**
     * Send text message.
     *
     * <code>
     * $params = [
     *   'type' => 'chat',
     *   'from' => '',
     *   'to' => '',
     *   'metadata' => [
     *      'text' => '',
     *    ]
     * ];
     * </code>
     *
     * @see https://app.zapmizer.com/docs
     *
     * @throws CouldNotSendNotification
     */
    public function sendMessage(array $params): ?ResponseInterface
    {
        if (blank($this->token)) {
            throw CouldNotSendNotification::zapmizerBotTokenNotProvided('You must provide your zapmizer bot token to make any API requests.');
        }

        try {
            return $this->httpClient()->post($this->getApiBaseUri() . '/messages', [
                'form_params' => $params,
                'headers' => ['Authorization' => 'Bearer ' . $this->token],
            ]);
        } catch (ClientException $exception) {
            throw CouldNotSendNotification::zapmizerRespondedWithAnError($exception);
        } catch (Exception $exception) {
            throw CouldNotSendNotification::couldNotCommunicateWithZapmizer($exception);
        }
    }

    /**
     * Get HttpClient.
     */
    protected function httpClient(): HttpClient
    {
        return $this->http;
    }
}
