<?php

namespace NotificationChannels\Zapmizer\Connect;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\ApiError;
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
    /**
     * Zapmizer's floor for `expires_in`: the same signature covers the hosted
     * page, the authorization and the pairing (whose QR window alone is five
     * minutes) — below it the session would die mid-pairing.
     */
    public const MIN_EXPIRES_IN = 900;

    public const MAX_EXPIRES_IN = 86400;

    protected Transport $transport;

    protected string $apiBaseUri;

    public function __construct(
        protected ?string $partnerId = null,
        protected ?string $partnerSecret = null,
        ?Transport $transport = null,
        ?string $apiBaseUri = null,
    ) {
        $this->transport = $transport ?? new GuzzleTransport();
        $this->apiBaseUri = rtrim($apiBaseUri ?? 'https://app.zapmizer.com/api/', '/');
    }

    public function getApiBaseUri(): string
    {
        return $this->apiBaseUri;
    }

    /**
     * Create the hosted authorization session. `redirectUri` is where Zapmizer
     * sends the user back with the single-use `code`; `state` rides along and
     * must be checked on the way back. `webhookUrl` is the receiver Zapmizer
     * registers on the team once the number pairs — its id and secret come
     * back with the token. `externalId` is the app's id for the customer,
     * the key of subscription() and checkout() once the connect is approved.
     *
     * @throws InvalidArgumentException
     * @throws ZapmizerConnectException
     */
    public function createSession(
        string $redirectUri,
        ?string $state = null,
        ?string $webhookUrl = null,
        ?int $expiresIn = null,
        ?string $externalId = null,
    ): ConnectSession {
        if ($externalId !== null) {
            $this->guardExternalId($externalId);
        }

        $this->guardState($state);

        $response = $this->request('POST', '/connect/sessions', [
            'json' => $this->withoutNulls([
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'webhook_url' => $webhookUrl,
                'expires_in' => $expiresIn === null ? null : min(max($expiresIn, self::MIN_EXPIRES_IN), self::MAX_EXPIRES_IN),
                'external_id' => $externalId,
            ]),
        ]);

        $this->guardFailure($response, 'connect/sessions');

        return ConnectSession::fromArray($this->decode($response), $externalId);
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

    protected function guardExternalId(string $externalId): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,191}\z/', $externalId) !== 1 || $externalId === '.' || $externalId === '..') {
            throw new InvalidArgumentException('The external id must be 1 to 191 characters among A-Z, a-z, 0-9, "_", "." and "-", and not "." or "..".');
        }
    }

    protected function guardState(?string $state): void
    {
        if ($state === '' || $state === '0') {
            throw new InvalidArgumentException('The state must not be "" or "0": Zapmizer drops it, and the redirect would come back without one.');
        }
    }

    protected function withoutNulls(array $body): array
    {
        return array_filter($body, fn ($value) => $value !== null);
    }

    protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if (blank($this->partnerId) || blank($this->partnerSecret)) {
            throw ZapmizerConnectException::partnerCredentialsNotProvided();
        }

        return (new ZapmizerApi($this->transport))->send($method, $this->apiBaseUri . $path, $options, [
            'X-Partner-Key' => "{$this->partnerId}|{$this->partnerSecret}",
        ]);
    }

    /**
     * A refused partner credential (401/403) is PartnerCredentialsException;
     * any other 4xx comes out of ZapmizerApi::failure(). The body is read
     * once and goes to the log too.
     *
     * @throws ZapmizerConnectException
     */
    protected function guardFailure(ResponseInterface $response, string $endpoint): void
    {
        if ($response->getStatusCode() < 400) {
            return;
        }

        $error = ApiError::from($response);

        Log::error('zapmizer: partner call failed.', [
            'endpoint' => $endpoint,
            'status' => $error->status,
            'body' => $error->body,
        ]);

        if (in_array($error->status, [401, 403], true)) {
            throw new PartnerCredentialsException($error);
        }

        throw ZapmizerApi::failure($error);
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
