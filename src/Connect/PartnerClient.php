<?php

namespace NotificationChannels\Zapmizer\Connect;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Class PartnerClient.
 *
 * Zapmizer's partner endpoints, authenticated by X-Partner-Key — never by a
 * team token. Creates the authorization session the popup opens and
 * exchanges the callback code for the authorized team's token.
 */
class PartnerClient
{
    protected HttpClient $http;

    protected string $apiBaseUri;

    public function __construct(
        protected ?string $partnerId = null,
        protected ?string $partnerSecret = null,
        ?HttpClient $httpClient = null,
        ?string $apiBaseUri = null,
    ) {
        $this->http = $httpClient ?? new HttpClient();
        $this->apiBaseUri = rtrim($apiBaseUri ?? 'https://app.zapmizer.com/api/', '/');
    }

    public function getApiBaseUri(): string
    {
        return $this->apiBaseUri;
    }

    /**
     * Create the hosted authorization session. `redirectUri` is where Zapmizer
     * sends the user back with the single-use `code`; `state` rides along and
     * must be checked on the way back.
     *
     * @throws ZapmizerConnectException
     */
    public function createSession(string $redirectUri, string $state, ?int $expiresIn = null): ConnectSession
    {
        $response = $this->request('POST', '/connect/sessions', [
            'json' => array_filter([
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'expires_in' => $expiresIn,
            ]),
        ]);

        $this->guardFailure($response, 'connect/sessions');

        return ConnectSession::fromArray($this->decode($response));
    }

    /**
     * Exchange the callback code for the team token. 404 means the code is
     * unknown, expired or already used — returns null so the caller treats it
     * as an exchange failure, without a stack trace.
     *
     * @throws ZapmizerConnectException
     */
    public function exchangeCode(string $code): ?ConnectToken
    {
        $response = $this->request('POST', '/connect/token', [
            'json' => ['code' => $code],
        ]);

        if ($response->getStatusCode() === 404) {
            return null;
        }

        $this->guardFailure($response, 'connect/token');

        return ConnectToken::fromArray($this->decode($response));
    }

    /**
     * @throws ZapmizerConnectException
     */
    protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if (blank($this->partnerId) || blank($this->partnerSecret)) {
            throw ZapmizerConnectException::partnerCredentialsNotProvided();
        }

        $options['http_errors'] = false;
        // Redirects are not followed: a refused credential redirects to the
        // login page, and following it would pass an HTML 200 off as an answer.
        $options['allow_redirects'] = false;
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept' => 'application/json',
            'X-Partner-Key' => "{$this->partnerId}|{$this->partnerSecret}",
        ]);

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
     * A refused partner credential (401/403) is the only 4xx the caller can
     * tell apart; every other failure becomes unavailability, with the detail
     * in the log and not in the response.
     *
     * @throws ZapmizerConnectException
     */
    protected function guardFailure(ResponseInterface $response, string $endpoint): void
    {
        $status = $response->getStatusCode();

        if ($status < 400) {
            return;
        }

        Log::error('zapmizer: partner call failed.', [
            'endpoint' => $endpoint,
            'status' => $status,
            'body' => (string) $response->getBody(),
        ]);

        if (in_array($status, [401, 403], true)) {
            throw new PartnerCredentialsException('Zapmizer refused the partner credentials.');
        }

        throw ZapmizerUnavailableException::dueTo();
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
