<?php

namespace NotificationChannels\Zapmizer;

use Illuminate\Container\Container;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\ZapmizerApi;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Messages\MetaMessageStatus;
use NotificationChannels\Zapmizer\Messages\QrMessageStatus;
use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use NotificationChannels\Zapmizer\Support\Payload;
use Psr\Http\Message\ResponseInterface;

/**
 * Class Zapmizer.
 */
class Zapmizer
{
    protected Transport $transport;

    /** @var null|string Zapmizer Bot API Token. */
    protected ?string $token;

    /** @var string Zapmizer Bot API Base URI */
    protected string $apiBaseUri;

    public function __construct(?string $token = null, ?Transport $transport = null, ?string $apiBaseUri = null, protected ?string $apiVersion = null)
    {
        $this->token = $token;
        $this->transport = $transport ?? Container::getInstance()->make(Transport::class);
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
     * The answer is the accepted send, not the delivery: follow it with
     * `track()`.
     *
     * @see https://app.zapmizer.com/docs
     *
     * @throws Exceptions\ZapmizerException
     */
    public function sendMessage(array $params): SentMessage
    {
        $this->guardToken();

        return SentMessage::fromArray($this->decode($this->post(['form_params' => $params])));
    }

    /**
     * @throws Exceptions\ZapmizerException
     */
    public function sendMessageWithFile(array $params, string $filePath): SentMessage
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

        return SentMessage::fromArray($this->decode($this->post([
            'multipart' => $multipart,
            'connect_timeout' => ZapmizerApi::TRANSFER_CONNECT_TIMEOUT,
            'timeout' => ZapmizerApi::TRANSFER_TIMEOUT,
        ])));
    }

    /**
     * Where a send from a QR number is now (`GET /sent-messages/{id}`).
     * Prefer `track()`, which reads the kind of number from the send.
     *
     * @throws InvalidArgumentException when `$id` is empty
     * @throws Exceptions\ZapmizerException
     */
    public function sentMessage(string $id): QrMessageStatus
    {
        if ($id === '') {
            throw new InvalidArgumentException('The sent message id must not be empty.');
        }

        $this->guardToken();

        return QrMessageStatus::fromArray($this->decode($this->request('GET', '/sent-messages/' . rawurlencode($id))));
    }

    /**
     * Where a send from the official number is now (`GET /meta-messages/{id}`).
     * Prefer `track()`, which reads the kind of number from the send.
     *
     * @throws InvalidArgumentException when `$id` is not a positive integer
     * @throws Exceptions\ZapmizerException
     */
    public function metaMessage(int|string $id): MetaMessageStatus
    {
        $id = Payload::positiveId($id);

        if ($id === null) {
            throw new InvalidArgumentException('The meta message id must be a positive integer.');
        }

        $this->guardToken();

        return MetaMessageStatus::fromArray($this->decode($this->request('GET', "/meta-messages/{$id}")));
    }

    /**
     * Follow a send by its `links.self` — the `SentMessage` itself or the
     * link stored from it. The link says whether the number is QR
     * (`sent-messages`) or official (`meta-messages`); the call goes to
     * this client's API base with this client's token, never to the host
     * written in the link. Null when the send has nothing to follow (an
     * official send that went through a queue, or no `links.self`): that is
     * not an error.
     *
     * @throws InvalidArgumentException when the link is not a tracking link
     *                                  (e.g. `/messages/{id}` of the legacy
     *                                  shape, sent without `api-version`)
     * @throws Exceptions\ZapmizerException
     */
    public function track(SentMessage|string $sent): QrMessageStatus|MetaMessageStatus|null
    {
        if ($sent instanceof SentMessage) {
            if (!$sent->isTrackable()) {
                return null;
            }

            $sent = $sent->selfUrl;
        }

        [$kind, $id] = $this->trackingLink($sent);

        return $kind === 'meta-messages' ? $this->metaMessage($id) : $this->sentMessage($id);
    }

    /**
     * The kind (`sent-messages` or `meta-messages`) and the id of a
     * `links.self`.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException
     */
    protected function trackingLink(string $link): array
    {
        $path = Payload::url($link, underscoreInHost: true) === null ? null : parse_url($link, PHP_URL_PATH);

        if (!is_string($path)
            || preg_match('#/(sent-messages|meta-messages)/([^/]+)\z#', $path, $match) !== 1
            || ($match[1] === 'meta-messages' && !ctype_digit($match[2]))) {
            throw new InvalidArgumentException('Not a tracking link (`sent-messages/{id}` or `meta-messages/{id}`): send with `api-version: 2025-06-27` to get one.');
        }

        return [$match[1], rawurldecode($match[2])];
    }

    /**
     * POST to the messages API — see `request()`.
     *
     * @throws Exceptions\ZapmizerException
     */
    protected function post(array $options): ResponseInterface
    {
        return $this->request('POST', '/messages', $options);
    }

    /**
     * Call the API as the token's connection and make sure what came back
     * is an API answer: 4xx become the API exceptions, 5xx and network
     * failures `ZapmizerUnavailableException`, a redirect or a non-JSON
     * answer `unexpectedResponse`. Redirects are not followed: with a
     * revoked token Zapmizer redirects to its login page, and following it
     * would turn a lost message into a 200.
     *
     * @throws Exceptions\ZapmizerException
     */
    protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $response = (new ZapmizerApi($this->transport))->send($method, $this->getApiBaseUri() . $path, $options, array_filter([
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
     * The JSON object of a successful answer.
     *
     * @throws ZapmizerConnectException
     */
    protected function decode(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);

        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw ZapmizerConnectException::unexpectedResponse('response body is not a JSON object');
        }

        return $payload;
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
