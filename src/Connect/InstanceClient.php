<?php

namespace NotificationChannels\Zapmizer\Connect;

use Illuminate\Container\Container;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

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
    protected Transport $transport;

    protected string $apiBaseUri;

    public function __construct(
        protected string $token,
        ?Transport $transport = null,
        ?string $apiBaseUri = null,
        protected ?string $apiVersion = null,
    ) {
        $this->transport = $transport ?? Container::getInstance()->make(Transport::class);
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
     * On `attached` the bytes are copied into a temporary file owned by the
     * returned object; on the way the transport keeps them in `php://temp`
     * (memory up to 2 MB, disk beyond).
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
            'connect_timeout' => ZapmizerApi::TRANSFER_CONNECT_TIMEOUT,
            'timeout' => ZapmizerApi::TRANSFER_TIMEOUT,
        ], expectsJson: false);

        return $this->mediaFrom($response);
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

    protected function temporaryMediaPath(): ?string
    {
        $path = @tempnam(sys_get_temp_dir(), 'zapmizer-media-');

        return $path === false ? null : $path;
    }

    protected function mediaFrom(ResponseInterface $response): MediaDownload
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
            $path = $this->spool($response->getBody());
            $size = $this->storedSize($response, $path);

            return MediaDownload::attached(
                path: $path,
                mimeType: $response->hasHeader('Content-Type') ? $response->getHeaderLine('Content-Type') : null,
                filename: $this->filenameFrom($response->getHeaderLine('Content-Disposition')),
                size: $size,
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

    /**
     * @throws ZapmizerConnectException
     */
    protected function spool(StreamInterface $body): string
    {
        if (!$body->isReadable()) {
            throw ZapmizerConnectException::unexpectedResponse('the media body was lost before it could be stored');
        }

        $path = $this->temporaryMediaPath();
        $target = $path === null ? false : @fopen($path, 'wb');

        if ($target === false) {
            if ($path !== null) {
                @unlink($path);
            }

            throw ZapmizerUnavailableException::dueTo();
        }

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (!$body->eof()) {
                $chunk = $body->read(65536);

                if ($chunk === '') {
                    break;
                }

                if (@fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write the media to the temporary file.');
                }
            }
        } catch (Throwable $exception) {
            fclose($target);
            @unlink($path);

            throw $exception instanceof RuntimeException ? ZapmizerUnavailableException::dueTo($exception) : $exception;
        }

        fclose($target);

        return $path;
    }

    protected function storedSize(ResponseInterface $response, string $path): int
    {
        clearstatcache(true, $path);
        $size = (int) @filesize($path);
        $expected = $response->hasHeader('Content-Length') && !$response->hasHeader('Transfer-Encoding')
            ? (int) $response->getHeaderLine('Content-Length')
            : null;

        if ($expected !== null && $size < $expected) {
            @unlink($path);

            if ($size === 0 && !$response->getBody()->isSeekable()) {
                throw ZapmizerConnectException::unexpectedResponse('the media body was lost before it could be stored');
            }

            throw ZapmizerUnavailableException::dueTo();
        }

        return $size;
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
        return (new ZapmizerApi($this->transport))->send($method, $this->apiBaseUri . $path, $options, array_filter([
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
