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
 * Talks to the Zapmizer verification API: requests a new verification for a
 * phone number and fetches the state of an existing one.
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
     * Request a new verification for the given phone number.
     *
     * Returns the verification identifier, the hosted page link and the
     * initial state.
     *
     * @throws ZapmizerVerificationException
     */
    public function create(string $number): Verification
    {
        $response = $this->request('POST', '/verifications', [
            'json' => ['number' => $number],
        ]);

        return Verification::fromPayload($this->decode($response));
    }

    /**
     * Fetch the state of an existing verification by its identifier.
     *
     * @throws ZapmizerVerificationException
     */
    public function get(string $id): Verification
    {
        $response = $this->request('GET', '/verifications/' . rawurlencode($id));

        return Verification::fromPayload($this->decode($response));
    }

    /**
     * Perform an authenticated request against the verification API.
     *
     * @throws ZapmizerVerificationException
     */
    protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if (blank($this->token)) {
            throw ZapmizerVerificationException::apiTokenNotProvided();
        }

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'api-version' => $this->apiVersion,
        ]);

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
