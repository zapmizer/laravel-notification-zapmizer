<?php

namespace NotificationChannels\Zapmizer;

use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use NotificationChannels\Zapmizer\Exceptions\CouldNotSendNotification;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;

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

    public function __construct(?string $token = null, ?HttpClient $httpClient = null, ?string $apiBaseUri = null, protected ?string $apiVersion = null)
    {
        $this->token = $token;
        $this->http = $httpClient;
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

        return $this->post(['form_params' => $params]);
    }

    public function sendMessageWithFile(array $params, string $filePath): ?ResponseInterface
    {
        if (blank($this->token)) {
            throw CouldNotSendNotification::zapmizerBotTokenNotProvided('You must provide your zapmizer bot token to make any API requests.');
        }

        try {
            $multipart = [
                [
                    'name' => 'uploaded_media',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath),
                ],
            ];
        } catch (Exception $exception) {
            throw CouldNotSendNotification::couldNotCommunicateWithZapmizer($exception);
        }

        foreach ($params as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => $value,
            ];
        }

        return $this->post(['multipart' => $multipart]);
    }

    /**
     * POST to the messages API and make sure what came back is an API
     * answer. Redirects are not followed: with a revoked token Zapmizer
     * redirects to its login page, and following it would turn a lost
     * message into a 200.
     *
     * @throws CouldNotSendNotification
     */
    protected function post(array $options): ResponseInterface
    {
        try {
            $response = $this->httpClient()->post($this->getApiBaseUri() . '/messages', $options + [
                'allow_redirects' => false,
                'headers' => array_filter([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                    'api-version' => $this->apiVersion,
                ]),
            ]);
        } catch (ClientException $exception) {
            throw CouldNotSendNotification::zapmizerRespondedWithAnError($exception);
        } catch (Exception $exception) {
            throw CouldNotSendNotification::couldNotCommunicateWithZapmizer($exception);
        }

        if (($problem = JsonResponse::problem($response)) !== null) {
            throw CouldNotSendNotification::zapmizerRespondedUnexpectedly($problem);
        }

        return $response;
    }

    /**
     * Get HttpClient.
     */
    protected function httpClient(): HttpClient
    {
        return $this->http;
    }
}
