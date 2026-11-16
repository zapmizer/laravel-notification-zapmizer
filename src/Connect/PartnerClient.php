<?php

namespace NotificationChannels\Zapmizer\Connect;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\Concerns\BuildsZapmizerRequests;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\ApiError;
use Psr\Http\Message\ResponseInterface;

/**
 * Class PartnerClient.
 *
 * Zapmizer's partner endpoints, authenticated by X-Partner-Key — never by a
 * team token. Creates the authorization session the popup opens, exchanges
 * the callback code for the authorized team's token, and reads the
 * subscription and creates the checkout of a customer by its `external_id`.
 *
 * It sends no `api-version`, on purpose: Zapmizer's partner routes do not
 * read it, and their answers are the same with or without it.
 */
class PartnerClient
{
    use BuildsZapmizerRequests;

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
        $this->transport = $transport ?? Container::getInstance()->make(Transport::class);
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
     * With a `webhookUrl`, the session asks for the webhook in
     * `webhookVersion`, or `zapmizer.connect.webhook_version` (`2026-10-20`,
     * the message contract, by default), sent as `webhook_version`; blank
     * leaves it to the partner account's default. It only applies to a
     * webhook the connect creates: a `webhookUrl` the team already had keeps
     * its version (`ConnectToken::$webhookVersion`). Zapmizer before
     * 1.148.17 ignores the field.
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
        ?string $webhookVersion = null,
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
                'expires_in' => $this->clampExpiresIn($expiresIn),
                'external_id' => $externalId,
                'webhook_version' => $webhookUrl === null ? null : $this->webhookVersion($webhookVersion),
            ]),
        ]);

        $this->guardFailure($response, 'connect/sessions');

        return ConnectSession::fromArray($this->decode($response), $externalId);
    }

    /**
     * The `webhook_version` of a session: the one given, or the config's.
     * Null (left out of the body) when blank.
     */
    protected function webhookVersion(?string $webhookVersion): ?string
    {
        $version = $webhookVersion ?? config('zapmizer.connect.webhook_version', InstanceClient::MESSAGE_CONTRACT_VERSION);
        $version = is_string($version) && trim($version) !== '' ? trim($version) : null;
        $known = [InstanceClient::LEGACY_WEBHOOK_VERSION, InstanceClient::MESSAGE_CONTRACT_VERSION];

        // Zapmizer refuses any other with a 422, which the connect would
        // report as `zapmizer_unavailable` on every attempt.
        if ($version !== null && !in_array($version, $known, true)) {
            throw new \InvalidArgumentException("Invalid zapmizer.connect.webhook_version `{$version}`: use " . implode(' or ', $known) . ', or leave it empty for the partner account\'s default.');
        }

        return $version;
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
     * @throws InvalidArgumentException
     * @throws ZapmizerConnectException
     */
    public function subscription(string $externalId): ?PartnerSubscription
    {
        $this->guardExternalId($externalId);

        $endpoint = 'partner/users/' . rawurlencode($externalId);
        $response = $this->request('GET', "/{$endpoint}");

        if ($response->getStatusCode() === 404) {
            return null;
        }

        $this->guardFailure($response, $endpoint);

        return PartnerSubscription::fromArray($this->decode($response), $externalId);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ZapmizerConnectException
     */
    public function checkout(string $externalId, string $redirectUri, ?string $state = null): PartnerCheckout
    {
        $this->guardExternalId($externalId);
        $this->guardState($state);

        $endpoint = 'partner/users/' . rawurlencode($externalId) . '/checkout';
        $response = $this->request('POST', "/{$endpoint}", [
            'json' => $this->withoutNulls([
                'redirect_uri' => $redirectUri,
                'state' => $state,
            ]),
        ]);

        $this->guardFailure($response, $endpoint);

        return PartnerCheckout::fromArray($this->decode($response), $externalId);
    }

    protected function guardExternalId(string $externalId): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,191}\z/', $externalId) !== 1 || $externalId === '.' || $externalId === '..') {
            throw new InvalidArgumentException('The external id must be 1 to 191 characters among A-Z, a-z, 0-9, "_", "." and "-", and not "." or "..".');
        }
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
     * once and goes to the log too, except on 404 and 409: those are
     * business answers (no connect yet, already subscribed, payment pending).
     * A 429 is expected and handled by the caller: it is a warning with the
     * Retry-After, without the body.
     *
     * @throws ZapmizerConnectException
     */
    protected function guardFailure(ResponseInterface $response, string $endpoint): void
    {
        if ($response->getStatusCode() < 400) {
            return;
        }

        $error = ApiError::from($response);

        if ($error->status === 429) {
            Log::warning('zapmizer: partner call rate-limited.', [
                'endpoint' => $endpoint,
                'retry_after' => $error->retryAfter,
            ]);
        } elseif (!in_array($error->status, [404, 409], true)) {
            Log::error('zapmizer: partner call failed.', [
                'endpoint' => $endpoint,
                'status' => $error->status,
                'body' => $error->body,
            ]);
        }

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
