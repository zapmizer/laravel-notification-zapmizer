<?php

namespace NotificationChannels\Zapmizer\Connect;

use GuzzleHttp\Client as HttpClient;
use NotificationChannels\Zapmizer\Connect\Concerns\UsesTransport;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;

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
    use UsesTransport;

    public const MEDIA_CONNECT_TIMEOUT = 60;

    public const MEDIA_TIMEOUT = 600;

    protected HttpClient $http;

    protected Transport $transport;

    protected string $apiBaseUri;

    public function __construct(
        protected string $token,
        ?HttpClient $httpClient = null,
        ?string $apiBaseUri = null,
        protected ?string $apiVersion = null,
        ?Transport $transport = null,
    ) {
        $this->transport = $transport ?? new GuzzleTransport($httpClient ?? new HttpClient());
        $this->http = $this->transport instanceof GuzzleTransport ? $this->transport->client() : ($httpClient ?? new HttpClient());
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
        $path = $this->temporaryMediaPath();
        $response = null;
        $download = null;

        try {
            $response = $this->request('GET', '/whatsapp-messages/media', array_merge([
                'query' => [
                    'bot_instance_id' => $botInstanceId,
                    'message_id' => $messageId,
                    'timestamp' => $timestamp,
                ],
                'connect_timeout' => self::MEDIA_CONNECT_TIMEOUT,
                'timeout' => self::MEDIA_TIMEOUT,
            ], $path === null ? ['stream' => true] : ['sink' => $path]), expectsJson: false);

            return $download = $this->mediaFrom($response, $path);
        } finally {
            if ($response !== null && $this->ownsBody($response, $path)) {
                $response->getBody()->close();
            }

            if ($path !== null && ($download === null || !$download->isAttached()) && is_file($path)) {
                @unlink($path);
            }
        }
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
     * Whether the body is one this call opened and must close — the sink
     * file, or, under `stream`, the 200 left unread on the connection (the
     * other answers are read whole). A fake (`Http::fake` in array form)
     * hands back the same response on every call; closing its body would
     * break the next one.
     */
    protected function ownsBody(ResponseInterface $response, ?string $path): bool
    {
        if ($path === null) {
            return $response->getStatusCode() === 200;
        }

        return $response->getBody()->getMetadata('uri') === $path;
    }

    protected function temporaryMediaPath(): ?string
    {
        $path = @tempnam(sys_get_temp_dir(), 'zapmizer-media-');

        return $path === false ? null : $path;
    }

    protected function mediaFrom(ResponseInterface $response, ?string $path): MediaDownload
    {
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
            if ($path === null) {
                throw ZapmizerConnectException::unexpectedResponse('could not create a temporary file for the media');
            }

            return MediaDownload::attached(
                path: $this->storedMedia($response, $path),
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
                default => $status === 404
                    ? MediaDownload::unavailable()
                    : throw ZapmizerConnectException::unexpectedResponse('media response carries no known `media_state`'),
            };
        }

        $this->guardFailure($response);

        throw ZapmizerConnectException::unexpectedResponse("HTTP {$status} on the media endpoint");
    }

    protected function storedMedia(ResponseInterface $response, string $path): string
    {
        clearstatcache(true, $path);
        $total = (int) filesize($path);
        $body = $response->getBody();

        if ($total === 0) {
            if (!$body->isReadable()) {
                throw ZapmizerConnectException::unexpectedResponse('the media body was lost before it could be stored');
            }

            if ($body->isSeekable()) {
                $body->rewind();
            }

            $target = fopen($path, 'wb');

            try {
                while (!$body->eof()) {
                    $chunk = $body->read(65536);

                    if ($chunk === '') {
                        break;
                    }

                    $total += (int) fwrite($target, $chunk);
                }
            } finally {
                fclose($target);
            }
        }

        $expected = $response->hasHeader('Content-Length') && !$response->hasHeader('Transfer-Encoding')
            ? (int) $response->getHeaderLine('Content-Length')
            : null;

        if ($expected !== null && $total < $expected) {
            if ($total === 0 && !$body->isSeekable()) {
                throw ZapmizerConnectException::unexpectedResponse('the media body was lost before it could be stored');
            }

            throw ZapmizerUnavailableException::dueTo();
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

    protected function request(string $method, string $path, array $options = [], bool $expectsJson = true): ResponseInterface
    {
        return (new ZapmizerApi($this->currentTransport()))->send($method, $this->apiBaseUri . $path, $options, array_filter([
            'Authorization' => 'Bearer ' . $this->token,
            'api-version' => $this->apiVersion,
        ]), $expectsJson);
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
