<?php

namespace NotificationChannels\Zapmizer;

use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Connect\ZapmizerApi;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Class Zapmizer.
 */
class Zapmizer
{
    public const UPLOAD_CONNECT_TIMEOUT = 60;

    public const UPLOAD_TIMEOUT = 600;

    protected Transport $transport;

    /** @var null|string Zapmizer Bot API Token. */
    protected ?string $token;

    /** @var string Zapmizer Bot API Base URI */
    protected string $apiBaseUri;

    public function __construct(?string $token = null, ?Transport $transport = null, ?string $apiBaseUri = null, protected ?string $apiVersion = null)
    {
        $this->token = $token;
        $this->transport = $transport ?? new GuzzleTransport();
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
     * @throws Exceptions\ZapmizerException
     */
    public function sendMessage(array $params): ?ResponseInterface
    {
        $this->guardToken();

        return $this->post(['form_params' => $params]);
    }

    /**
     * @throws Exceptions\ZapmizerException
     */
    public function sendMessageWithFile(array $params, string $filePath): ?ResponseInterface
    {
        $this->guardToken();

        $file = @fopen($filePath, 'r');

        if ($file === false) {
            throw ZapmizerConnectException::unreadableFile($filePath);
        }

        $multipart = [
            [
                'name' => 'uploaded_media',
                'contents' => $file,
                'filename' => basename($filePath),
            ],
        ];

        foreach ($params as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => $value,
            ];
        }

        return $this->post([
            'multipart' => $multipart,
            'connect_timeout' => self::UPLOAD_CONNECT_TIMEOUT,
            'timeout' => self::UPLOAD_TIMEOUT,
        ]);
    }

    /**
     * POST to the messages API through the transport and make sure what
     * came back is an API answer. Redirects are not followed: with a revoked
     * token Zapmizer redirects to its login page, and following it would
     * turn a lost message into a 200.
     *
     * @throws Exceptions\ZapmizerException
     */
    protected function post(array $options): ResponseInterface
    {
        $response = (new ZapmizerApi($this->transport))->send('POST', $this->getApiBaseUri() . '/messages', $options, array_filter([
            'Authorization' => 'Bearer ' . $this->token,
            'api-version' => $this->apiVersion,
        ]));

        if ($response->getStatusCode() >= 400) {
            throw ZapmizerApi::failure($response);
        }

        if (($problem = JsonResponse::problem($response)) !== null) {
            throw ZapmizerConnectException::unexpectedResponse($problem);
        }

        return $response;
    }

    /**
     * @throws ZapmizerUnauthorizedException
     */
    protected function guardToken(): void
    {
        if (blank($this->token)) {
            $message = 'You must provide your zapmizer bot token to make any API requests.';

            throw ZapmizerUnauthorizedException::withoutResponse($message);
        }
    }
}
