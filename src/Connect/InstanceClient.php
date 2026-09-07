<?php

namespace NotificationChannels\Zapmizer\Connect;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Class InstanceClient.
 *
 * Zapmizer's instance-connection and webhook endpoints, authenticated by
 * the connected team's token (the one the connect flow stored). Pairing
 * itself happens on Zapmizer's hosted page — this client only reads the
 * state of the paired instance and manages the webhook. The token is
 * always per connection — there is no single-tenant fallback on purpose:
 * resolve it through `ZapmizerConnection::instanceClient()`.
 */
class InstanceClient
{
    protected HttpClient $http;

    protected string $apiBaseUri;

    public function __construct(
        protected string $token,
        ?HttpClient $httpClient = null,
        ?string $apiBaseUri = null,
        protected ?string $apiVersion = null,
    ) {
        $this->http = $httpClient ?? new HttpClient();
        $this->apiBaseUri = rtrim($apiBaseUri ?? 'https://app.zapmizer.com/api/', '/');
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getApiBaseUri(): string
    {
        return $this->apiBaseUri;
    }

    /**
     * The pairing state of an instance — whether the paired number is online.
     *
     * @throws ZapmizerConnectException
     */
    public function connection(int $id): InstanceConnection
    {
        $response = $this->request('GET', "/bot-instances/{$id}/connection");

        $this->guardUnauthorized($response);

        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
            throw new InstanceGoneException("Instance {$id} is gone on Zapmizer.");
        }

        $this->guardFailure($response);

        return InstanceConnection::fromArray($this->decode($response));
    }

    /**
     * Register a webhook receiver. Zapmizer returns the `secret` ONLY in this
     * response — the caller must persist it.
     *
     * @throws ZapmizerConnectException
     */
    public function createWebhook(string $url): WebhookRegistration
    {
        $response = $this->request('POST', '/webhooks', [
            'json' => ['url' => $url, 'enabled' => true],
        ]);

        $this->guardUnauthorized($response);
        $this->guardFailure($response);

        return WebhookRegistration::fromArray($this->decode($response));
    }

    /**
     * Rotate a webhook secret. The response carries the new secret and the
     * previous one — Zapmizer signs with both until the next rotation.
     *
     * @throws ZapmizerConnectException
     */
    public function rotateWebhookSecret(int $webhookId): WebhookRegistration
    {
        $response = $this->request('POST', "/webhooks/{$webhookId}/secret");

        $this->guardUnauthorized($response);
        $this->guardFailure($response);

        return WebhookRegistration::fromArray($this->decode($response));
    }

    /**
     * Delete a webhook. An already-deleted one (404) counts as done.
     *
     * @throws ZapmizerConnectException
     */
    public function deleteWebhook(int $webhookId): void
    {
        $response = $this->request('DELETE', "/webhooks/{$webhookId}");

        $this->guardUnauthorized($response);

        if ($response->getStatusCode() === 404) {
            return;
        }

        $this->guardFailure($response);
    }

    /**
     * The media of an inbound message. The `message` webhook fires before
     * the bot has finished downloading it, so the answer may be
     * `downloading` (ask again later — see `ZapmizerConnection::awaitMedia()`)
     * or `unavailable` (it will never come: outside the 600 s window from
     * `$timestamp`, a type Zapmizer does not download, a view-once message).
     * On `attached` the bytes are streamed into a temporary file owned by the
     * returned object — never held in memory as a string.
     *
     * A 200 here is binary by design, so the JSON guard the other endpoints
     * run through is skipped for it; 202 and 404 are JSON with `media_state`.
     * Two answers are the caller's fault and come back as exceptions with
     * the reason: 422 (a bad request — a `timestamp` in the future, or an
     * instance on Meta Cloud, which has no media endpoint) and 429 (over the
     * limit of 60 requests a minute per user and instance).
     *
     * @param string $messageId `id._serialized` or `id.id` of the message
     * @param int $timestamp The message's `timestamp` (seconds) — required:
     *                       the 600 s window Zapmizer keeps downloading in
     *                       is counted from it, and it must not be in the future
     *
     * @throws ZapmizerConnectException
     */
    public function media(int $botInstanceId, string $messageId, int $timestamp): MediaDownload
    {
        $response = $this->request('GET', '/whatsapp-messages/media', [
            'query' => [
                'bot_instance_id' => $botInstanceId,
                'message_id' => $messageId,
                'timestamp' => $timestamp,
            ],
            'stream' => true,
        ], expectsJson: false);

        $this->guardUnauthorized($response);

        $status = $response->getStatusCode();

        if ($status === 422) {
            throw ZapmizerConnectException::mediaRejected($this->reasonFrom($response));
        }

        if ($status === 429) {
            throw ZapmizerConnectException::mediaRateLimited(
                $response->hasHeader('Retry-After') ? (int) $response->getHeaderLine('Retry-After') : null,
            );
        }

        if ($status === 200) {
            return MediaDownload::attached(
                path: $this->spool($response->getBody()),
                mimeType: $response->hasHeader('Content-Type') ? $response->getHeaderLine('Content-Type') : null,
                filename: $this->filenameFrom($response->getHeaderLine('Content-Disposition')),
                size: $response->hasHeader('Content-Length') ? (int) $response->getHeaderLine('Content-Length') : null,
            );
        }

        if ($status === 202 || $status === 404) {
            if (($problem = JsonResponse::problem($response)) !== null) {
                throw ZapmizerConnectException::unexpectedResponse($problem);
            }

            return match ($this->decode($response)['media_state'] ?? null) {
                MediaDownload::DOWNLOADING => MediaDownload::downloading(),
                MediaDownload::UNAVAILABLE => MediaDownload::unavailable(),
                // A 404 without `media_state`: the instance is not this team's.
                default => $status === 404
                    ? MediaDownload::unavailable()
                    : throw ZapmizerConnectException::unexpectedResponse('media response carries no known `media_state`'),
            };
        }

        $this->guardFailure($response);

        throw ZapmizerConnectException::unexpectedResponse("HTTP {$status} on the media endpoint");
    }

    /**
     * The reason of a JSON error answer: Laravel's `message`, plus the first
     * of each validation error when there is one. The raw body otherwise.
     */
    protected function reasonFrom(ResponseInterface $response): string
    {
        $body = (string) $response->getBody();
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            return trim($body);
        }

        $errors = array_map(
            fn ($messages) => is_array($messages) ? (string) reset($messages) : (string) $messages,
            $payload['errors'] ?? [],
        );

        return implode(' ', array_filter([$payload['message'] ?? null, ...array_values($errors)]))
            ?: trim($body);
    }

    /**
     * Copy the response body into a temporary file, chunk by chunk.
     *
     * @throws ZapmizerConnectException
     */
    protected function spool(StreamInterface $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zapmizer-media-');
        $file = $path !== false ? fopen($path, 'wb') : false;

        if ($path === false || $file === false) {
            throw ZapmizerConnectException::unexpectedResponse('could not create a temporary file for the media');
        }

        try {
            while (!$body->eof()) {
                fwrite($file, $body->read(65536));
            }
        } finally {
            fclose($file);
            $body->close();
        }

        return $path;
    }

    /**
     * The `filename` of a `Content-Disposition: attachment; filename="x"`
     * header (RFC 5987 `filename*=` accepted too). Null when absent.
     */
    protected function filenameFrom(string $disposition): ?string
    {
        if (preg_match("/filename\*=(?:UTF-8|utf-8)''([^;]+)/", $disposition, $match)) {
            return rawurldecode(trim($match[1]));
        }

        if (preg_match('/filename="((?:[^"\\\\]|\\\\.)*)"/', $disposition, $match)) {
            return stripslashes($match[1]);
        }

        if (preg_match('/filename=([^;\s]+)/', $disposition, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param bool $expectsJson Off for the one endpoint whose 200 is binary
     *                          (media). A redirect is still refused there —
     *                          it means the token was rejected.
     *
     * @throws ZapmizerConnectException
     */
    protected function request(string $method, string $path, array $options = [], bool $expectsJson = true): ResponseInterface
    {
        $options['http_errors'] = false;
        // Redirects are not followed: a refused credential redirects to the
        // login page, and following it would pass an HTML 200 off as an answer.
        $options['allow_redirects'] = false;
        $options['headers'] = array_merge($options['headers'] ?? [], array_filter([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'api-version' => $this->apiVersion,
        ]));

        try {
            $response = $this->http->request($method, $this->apiBaseUri . $path, $options);
        } catch (GuzzleException $exception) {
            throw ZapmizerUnavailableException::dueTo($exception);
        }

        if ($response->getStatusCode() >= 500) {
            throw ZapmizerUnavailableException::dueTo();
        }

        if ($response->getStatusCode() < 400) {
            $problem = $expectsJson
                ? JsonResponse::problem($response, sniffBody: false)
                : JsonResponse::redirected($response);

            if ($problem !== null) {
                throw ZapmizerConnectException::unexpectedResponse($problem);
            }
        }

        return $response;
    }

    /**
     * @throws ZapmizerUnauthorizedException
     */
    protected function guardUnauthorized(ResponseInterface $response): void
    {
        if ($response->getStatusCode() === 401) {
            throw new ZapmizerUnauthorizedException('Zapmizer refused the connection token.');
        }
    }

    /**
     * @throws ZapmizerConnectException
     */
    protected function guardFailure(ResponseInterface $response): void
    {
        if ($response->getStatusCode() >= 400) {
            throw ZapmizerConnectException::unexpectedResponse(
                "HTTP {$response->getStatusCode()} - " . (string) $response->getBody()
            );
        }
    }

    /**
     * @throws ZapmizerConnectException
     */
    protected function decode(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);

        if (!is_array($payload)) {
            throw ZapmizerConnectException::unexpectedResponse('response body is not valid JSON');
        }

        return $payload;
    }
}
