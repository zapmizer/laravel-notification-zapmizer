<?php

namespace NotificationChannels\Zapmizer;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use NotificationChannels\Zapmizer\Exceptions\VerificationConnectionFailed;
use NotificationChannels\Zapmizer\Exceptions\VerificationRequestFailed;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;
use Psr\Http\Message\ResponseInterface;

/**
 * Class VerificationClient.
 *
 * Talks to the Zapmizer verify-number API: creates hosted verification page
 * sessions and confirms the OTP code the end user received on WhatsApp.
 *
 * Authentication uses the team's API token (the same Sanctum token as the
 * messages API), sent as a Bearer token; everything is scoped to the team
 * the token belongs to.
 */
class VerificationClient
{
    /** @var HttpClient HTTP Client */
    protected HttpClient $http;

    /** @var null|string Zapmizer API Token. */
    protected ?string $token;

    /** @var string Zapmizer API Base URI */
    protected string $apiBaseUri;

    public function __construct(?string $token = null, ?HttpClient $httpClient = null, ?string $apiBaseUri = null, protected ?string $apiVersion = null)
    {
        $this->token = $token;
        $this->http = $httpClient ?? new HttpClient();
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
     * Create a hosted verification page session.
     *
     * Returns the signed, temporary URL to redirect the end user to — the
     * wa.me trigger and the code input live there. Pass `$number` to prefill
     * the number input, and `$from` to pick which of the team's WhatsApp
     * numbers receives the verification (omitted, Zapmizer uses the first
     * online one).
     *
     * @throws ZapmizerVerificationException
     */
    public function createSession(?string $number = null, ?string $from = null, ?int $expiresIn = null): VerificationSession
    {
        $response = $this->request('POST', '/verify-number/sessions', [
            'json' => array_filter([
                'number' => $number,
                'from' => $from,
                'expires_in' => $expiresIn,
            ]),
        ]);

        return VerificationSession::fromPayload($this->decode($response));
    }

    /**
     * Confirm the OTP code the end user received on WhatsApp.
     *
     * Always resolves to a result (HTTP 200): `verified`, `invalid` (with
     * attempts left), `failed` (attempts exhausted) or `not_found` — the
     * latter also covers the short window while the code is still
     * propagating, so treat it as "retry in a few seconds".
     *
     * @throws ZapmizerVerificationException
     */
    public function confirm(string $number, string $code): VerificationResult
    {
        $response = $this->request('POST', '/verify-number/confirm', [
            'json' => ['number' => $number, 'code' => $code],
        ]);

        return VerificationResult::fromPayload($this->decode($response));
    }

    /**
     * Perform an authenticated request against the verify-number API.
     *
     * @throws ZapmizerVerificationException
     */
    protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if (blank($this->token)) {
            throw ZapmizerVerificationException::apiTokenNotProvided();
        }

        $options['headers'] = array_merge($options['headers'] ?? [], array_filter([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'api-version' => $this->apiVersion,
        ]));

        try {
            return $this->http->request($method, $this->getApiBaseUri() . $path, $options);
        } catch (BadResponseException $exception) {
            throw VerificationRequestFailed::fromResponse($exception);
        } catch (GuzzleException $exception) {
            throw VerificationConnectionFailed::dueTo($exception);
        }
    }

    /**
     * Decode a JSON response body into an array.
     *
     * @throws ZapmizerVerificationException
     */
    protected function decode(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);

        if (!is_array($payload)) {
            throw ZapmizerVerificationException::unexpectedResponse('response body is not valid JSON');
        }

        return $payload;
    }
}
