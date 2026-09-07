<?php

namespace NotificationChannels\Zapmizer\Connect;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use NotificationChannels\Zapmizer\Exceptions\InstanceBootingException;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\InstancePlanLimitException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Class InstanceClient.
 *
 * Zapmizer's bot-instance and webhook endpoints, authenticated by the
 * connected team's token (the one the connect flow stored). The token is
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
     * List the team's instances. `per_page` is high on purpose: the index is
     * paginated, and without it the connected list would be truncated.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ZapmizerConnectException
     */
    public function instances(bool $connected = false): array
    {
        $response = $this->request('GET', '/bot-instances', [
            'query' => $connected ? ['connected' => 1, 'per_page' => 100] : [],
        ]);

        $this->guardUnauthorized($response);
        $this->guardFailure($response);

        $json = $this->decode($response);

        return array_values($json['data'] ?? $json);
    }

    /**
     * Create an instance — or, given `$botInstanceId`, boot the existing one
     * again (Zapmizer re-bootstraps a `disconnected`/`off` instance through
     * the same POST, with the id in the body).
     *
     * A 423 means a boot is already in progress. When Zapmizer knows which
     * instance is booting it says so in the body (`bot_instance_id`), and
     * the exception carries it: whoever retries must reuse that id, or the
     * retry creates a second instance once the boot ends.
     *
     * @return array<string, mixed>
     *
     * @throws ZapmizerConnectException
     */
    public function createInstance(?int $botInstanceId = null): array
    {
        $response = $this->request('POST', '/bot-instances', $botInstanceId === null ? [] : [
            'json' => ['bot_instance_id' => $botInstanceId],
        ]);

        $this->guardUnauthorized($response);

        if ($response->getStatusCode() === 402) {
            $json = $this->decode($response);

            throw new InstancePlanLimitException((string) ($json['message'] ?? 'Instance limit of the Zapmizer plan reached.'));
        }

        if ($response->getStatusCode() === 423) {
            $json = json_decode((string) $response->getBody(), true);
            $bootingId = is_array($json) && is_numeric($json['bot_instance_id'] ?? null) ? (int) $json['bot_instance_id'] : null;

            // Re-booting an id Zapmizer no longer knows is also a 423 over
            // there ("account not found") — without a booting id. For the
            // caller that is a gone instance, not a boot to wait for.
            if ($bootingId === null && $botInstanceId !== null) {
                throw new InstanceGoneException("Instance {$botInstanceId} is gone on Zapmizer.");
            }

            throw InstanceBootingException::inProgress($bootingId);
        }

        $this->guardFailure($response);

        $json = $this->decode($response);

        return $json['data'] ?? $json;
    }

    /**
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
     * @throws ZapmizerConnectException
     */
    protected function request(string $method, string $path, array $options = []): ResponseInterface
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

        if (($problem = JsonResponse::problem($response, sniffBody: false)) !== null && $response->getStatusCode() < 400) {
            throw ZapmizerConnectException::unexpectedResponse($problem);
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
