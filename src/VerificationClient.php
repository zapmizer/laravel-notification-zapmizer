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
 * Talks to the Zapbot verify-number API: starts a verification session for a
 * phone number, polls its state and confirms the code the user received.
 *
 * Authentication uses the publishable key (`pk_...`) sent in the
 * `X-Publishable-Key` header. The key is origin-allowlisted on the Zapbot
 * side, so requests also carry an `Origin` header — make sure it is listed
 * in the verification's allowed origins.
 */
class VerificationClient
{
    /** @var HttpClient HTTP Client */
    protected HttpClient $http;

    /** @var null|string Zapbot publishable key (pk_...). */
    protected ?string $token;

    /** @var string Zapbot API Base URI */
    protected string $apiBaseUri;

    public function __construct(
        ?string $token = null,
        ?HttpClient $httpClient = null,
        ?string $apiBaseUri = null,
        protected ?string $apiVersion = null,
        protected ?string $origin = null,
        protected ?string $secretKey = null,
    ) {
        $this->token = $token;
        $this->http = $httpClient ?? new HttpClient();
        $this->setApiBaseUri($apiBaseUri ?? 'https://app.zapmizer.com/api/');
    }

    /**
     * Publishable key getter.
     */
    public function getPublishableKey(): ?string
    {
        return $this->token;
    }

    /**
     * Publishable key setter.
     *
     * @return $this
     */
    public function setPublishableKey(string $publishableKey): self
    {
        $this->token = $publishableKey;

        return $this;
    }

    /**
     * Origin getter.
     */
    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    /**
     * Origin setter.
     *
     * @return $this
     */
    public function setOrigin(?string $origin): self
    {
        $this->origin = $origin;

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
     * Secret key getter.
     */
    public function getSecretKey(): ?string
    {
        return $this->secretKey;
    }

    /**
     * Secret key setter.
     *
     * @return $this
     */
    public function setSecretKey(?string $secretKey): self
    {
        $this->secretKey = $secretKey;

        return $this;
    }

    /**
     * Create a hosted verification page session.
     *
     * Server-side call authenticated with the secret key (sk_...). Returns
     * the session whose `url` the end user must be redirected to — the whole
     * verification happens there, on the Zapbot domain. When it completes,
     * the user is sent back to `returnUrl` with signed query params (see
     * ZapbotSignature::isValidQuery()).
     *
     * @throws ZapmizerVerificationException
     */
    public function createSession(?string $returnUrl = null, ?string $clientReference = null, ?int $expiresIn = null): VerificationSession
    {
        if (blank($this->secretKey)) {
            throw ZapmizerVerificationException::secretKeyNotProvided();
        }

        try {
            $response = $this->http->request('POST', $this->getApiBaseUri() . '/verify-number/sessions', [
                'json' => array_filter([
                    'return_url' => $returnUrl,
                    'client_reference' => $clientReference,
                    'expires_in' => $expiresIn,
                ]),
                'headers' => array_filter([
                    'Authorization' => 'Bearer ' . $this->secretKey,
                    'Accept' => 'application/json',
                    'api-version' => $this->apiVersion,
                ]),
            ]);
        } catch (BadResponseException $exception) {
            throw VerificationRequestFailed::fromResponse($exception);
        } catch (GuzzleException $exception) {
            throw VerificationConnectionFailed::dueTo($exception);
        }

        return VerificationSession::fromPayload($this->decode($response));
    }

    /**
     * Start a verification session for the given phone number.
     *
     * Idempotent on the Zapbot side: if there's already an active session
     * for the number, it is returned instead of creating a new one. While
     * the session is `resolving` the wa.me link is not available yet — poll
     * with get() until it becomes `pending`.
     *
     * @throws ZapmizerVerificationException
     */
    public function create(string $number, ?string $callbackUrl = null, ?string $clientReference = null): Verification
    {
        $response = $this->request('POST', '/verify-number', [
            'json' => array_filter([
                'number' => $number,
                'callback_url' => $callbackUrl,
                'client_reference' => $clientReference,
            ]),
        ]);

        return Verification::fromPayload($this->decode($response));
    }

    /**
     * Fetch the state of the latest verification session for a number.
     *
     * @throws ZapmizerVerificationException
     */
    public function get(string $number): Verification
    {
        $response = $this->request('GET', '/verify-number/' . rawurlencode($number));

        return Verification::fromPayload($this->decode($response));
    }

    /**
     * Confirm the code the user received on WhatsApp.
     *
     * A wrong code comes back as a 422 (VerificationRequestFailed); too many
     * attempts lock the session into `failed`.
     *
     * @throws ZapmizerVerificationException
     */
    public function confirm(string $number, string $code): Verification
    {
        $response = $this->request('POST', '/verify-number/' . rawurlencode($number) . '/confirm', [
            'json' => ['code' => $code],
        ]);

        return Verification::fromPayload($this->decode($response));
    }

    /**
     * Perform an authenticated request against the verify-number API.
     *
     * @throws ZapmizerVerificationException
     */
    protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if (blank($this->token)) {
            throw ZapmizerVerificationException::publishableKeyNotProvided();
        }

        $options['headers'] = array_merge($options['headers'] ?? [], array_filter([
            'X-Publishable-Key' => $this->token,
            'Origin' => $this->origin,
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
