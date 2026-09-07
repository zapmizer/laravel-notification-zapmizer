<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Connect\ConnectToken;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use Throwable;

/**
 * Class ConnectController.
 *
 * The hosted connect flow: `start` opens Zapmizer's page in a popup, where
 * the user authorizes a team AND pairs the WhatsApp number; `callback`
 * exchanges the code and lands with everything — token, number, instance,
 * webhook. There is no wizard on this side. Which model is being connected
 * comes from the configured `zapmizer.connect.resolver`; the model
 * implements Contracts\Connectable (through the Connectable trait).
 *
 * The popup reports its outcome through postMessage with a stable `status`:
 * `ok`, `denied`, `plan_limit`, `qr_unavailable`, `invalid_state`,
 * `exchange_failed`, `webhook_failed`, `team_already_connected`,
 * `no_connectable`. The JSON endpoints answer `zapmizer_unavailable` (503),
 * `partner_unauthorized` (503) and `no_connectable` (403) through their
 * exceptions.
 */
class ConnectController extends Controller
{
    public const SESSION_KEY = 'zapmizer_connect';

    /**
     * What the hosted page reports in `?error=` when it ends without a code,
     * and the postMessage status each one becomes.
     */
    protected const POPUP_ERRORS = [
        'access_denied' => 'denied',
        'plan_limit' => 'plan_limit',
        'qr_unavailable' => 'qr_unavailable',
    ];

    public function __construct(
        protected PartnerClient $partnerClient,
        protected ResolvesConnectable $resolver,
    ) {
    }

    /**
     * The current connection state, so the screen opens knowing where it is.
     * With `?live=1` the paired instance is queried on Zapmizer and the
     * connection carries `state` (`connected`, `disconnected`, ... or the
     * package's `reauth_required`, `instance_gone`, `zapmizer_unavailable`)
     * and `is_online` — a panel showing whether the number is up.
     */
    public function show(Request $request): JsonResponse
    {
        $connection = $this->connectable($request)->zapmizerConnection;

        if ($connection === null || !$request->boolean('live')) {
            return new JsonResponse(['connection' => $connection]);
        }

        return new JsonResponse(['connection' => $connection->toArray() + $this->liveState($connection)]);
    }

    /**
     * Create the authorization session and return the popup URL. The
     * webhook route goes along: Zapmizer registers it on the team once the
     * number pairs, and the id/secret come back with the token.
     */
    public function start(Request $request): JsonResponse
    {
        $connectable = $this->connectable($request);
        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'connectable' => $this->connectableKey($connectable),
            'expires_at' => now()->addMinutes((int) config('zapmizer.connect.state_ttl_minutes', 10))->toIso8601String(),
        ]);

        $session = $this->partnerClient->createSession(
            redirectUri: route('zapmizer.connect.callback'),
            state: $state,
            webhookUrl: route('zapmizer.webhook'),
        );

        return new JsonResponse($session);
    }

    /**
     * The popup lands here with the code (or an error). Renders a page that
     * reports the outcome to the opener through postMessage and closes
     * itself — so nothing here may escape as a 500: an HTML error page in
     * the popup never posts the message, and the opener waits forever.
     */
    public function callback(Request $request): View
    {
        // pull() guarantees the single use of the state, on error paths too.
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (($error = $request->query('error')) !== null) {
            return $this->result(static::POPUP_ERRORS[$error] ?? 'exchange_failed');
        }

        try {
            $connectable = $this->connectable($request);
        } catch (NoConnectableException) {
            return $this->result('no_connectable');
        }

        if (!$this->validState($pending, (string) $request->query('state', ''), $connectable)) {
            return $this->result('invalid_state');
        }

        try {
            $token = $this->partnerClient->exchangeCode((string) $request->query('code', ''));

            if ($token === null) {
                return $this->result('exchange_failed');
            }

            return $this->storeToken($connectable, $token);
        } catch (ZapmizerConnectException $exception) {
            // Partner key refused, Zapmizer down, non-JSON answer: all of
            // them are "try again" for the user, with the detail in the log.
            Log::warning('zapmizer: connect callback failed.', ['exception' => $exception->getMessage()]);

            return $this->result('exchange_failed');
        } catch (Throwable $exception) {
            report($exception);

            return $this->result('exchange_failed');
        }
    }

    /**
     * Store everything the exchange brought on the model's connection. The
     * hosted page paired the number before handing out the code, so the
     * connection is born ACTIVE — as long as a number came.
     *
     * A DIFFERENT team than the one stored gets a clean slate first: the old
     * number, instance and webhook belong to the old token, and the old
     * webhook is deleted over there (best-effort). The SAME team keeps its
     * stored webhook secret when Zapmizer reused the webhook (same id, no
     * secret in the answer); any other missing secret is obtained by
     * rotating — without one, no delivery can be verified.
     */
    protected function storeToken(Model&Connectable $connectable, ConnectToken $token): View
    {
        $connection = $connectable->zapmizerConnectionOrNew();

        // One connectable per Zapmizer team: two of them would register two
        // webhooks and every message would fire on both tenants.
        if ($token->teamId !== null && $this->teamConnectedElsewhere($connection, $token->teamId)) {
            return $this->result('team_already_connected');
        }

        $switchingTeam = $connection->exists
            && $connection->zapmizer_team_id !== null
            && $token->teamId !== null
            && $connection->zapmizer_team_id !== $token->teamId;

        // A webhook replaced on the same team (new id): the old one would keep
        // delivering with a secret this row is about to forget.
        $replacingWebhook = !$switchingTeam
            && $connection->webhook_id !== null
            && $token->webhookId !== null
            && $connection->webhook_id !== $token->webhookId;

        if ($switchingTeam || $replacingWebhook) {
            $this->forgetRemoteWebhook($connection);
        }

        if ($switchingTeam) {
            $connection->forgetPairing();
        }

        $reusedWebhook = $token->needsWebhookSecret()
            && $connection->webhook_id === $token->webhookId
            && filled($connection->webhook_secret);

        $connection->forceFill([
            'api_token' => $token->token,
            'zapmizer_team_id' => $token->teamId,
            'zapmizer_team_name' => $token->teamName,
            'phone_number' => $token->phoneNumber,
            'bot_instance_id' => $token->botInstanceId,
            'connected_at' => $token->phoneNumber === null ? null : now(),
            'webhook_id' => $token->webhookId,
            'webhook_secret' => $reusedWebhook ? $connection->webhook_secret : $token->webhookSecret,
            'webhook_previous_secret' => $reusedWebhook ? $connection->webhook_previous_secret : null,
            'is_active' => $token->phoneNumber !== null,
        ]);

        try {
            $connection->save();
        } catch (UniqueConstraintViolationException) {
            // Lost the race with another connectable authorizing the same
            // team between the check above and this insert.
            return $this->result('team_already_connected');
        }

        if ($token->needsWebhookSecret() && !$reusedWebhook && !$this->obtainWebhookSecret($connection)) {
            return $this->result('webhook_failed');
        }

        return $this->result('ok');
    }

    /**
     * Disconnect the model from Zapmizer: the webhook over there is deleted
     * (best-effort — a failure is logged and the local row goes anyway),
     * the local row with its credentials dies. The team token itself cannot
     * be revoked through the API; it is simply forgotten here.
     */
    public function destroy(Request $request): JsonResponse
    {
        $connection = $this->connectable($request)->zapmizerConnection;

        if ($connection !== null) {
            $this->forgetRemoteWebhook($connection);
            $connection->delete();
        }

        return new JsonResponse(['status' => 'disconnected']);
    }

    /**
     * Zapmizer reused a webhook the team already had for our URL and kept
     * its secret to itself. Rotating is the only way to get one; a
     * connection without it would accept nothing, so it is deactivated when
     * the rotation fails — the log says why, the popup says `webhook_failed`.
     */
    protected function obtainWebhookSecret(ZapmizerConnection $connection): bool
    {
        try {
            $connection->rotateWebhookSecret();

            return true;
        } catch (Throwable $exception) {
            Log::warning('zapmizer: could not obtain the webhook secret after connecting.', [
                'connection_id' => $connection->getKey(),
                'webhook_id' => $connection->webhook_id,
                'exception' => $exception->getMessage(),
            ]);

            $connection->forceFill(['is_active' => false])->save();

            return false;
        }
    }

    /**
     * The live state of the paired instance, folded into the connection's
     * JSON. Every failure becomes a `state` the panel can name instead of
     * an error the screen would have to map.
     *
     * @return array{state: ?string, is_online: bool}
     */
    protected function liveState(ZapmizerConnection $connection): array
    {
        if (!$connection->hasApiToken() || blank($connection->bot_instance_id)) {
            return ['state' => null, 'is_online' => false];
        }

        try {
            $instance = $connection->instanceClient()->connection((int) $connection->bot_instance_id);

            return ['state' => $instance->state, 'is_online' => $instance->isOnline];
        } catch (ZapmizerUnauthorizedException) {
            return ['state' => 'reauth_required', 'is_online' => false];
        } catch (InstanceGoneException) {
            return ['state' => 'instance_gone', 'is_online' => false];
        } catch (ZapmizerUnavailableException | ZapmizerConnectException) {
            return ['state' => 'zapmizer_unavailable', 'is_online' => false];
        }
    }

    /**
     * Best-effort deletion of the webhook on Zapmizer. Whatever the reason
     * it fails (token revoked, Zapmizer down), the local decision stands;
     * the log is what tells an operator to clean it up by hand.
     */
    protected function forgetRemoteWebhook(ZapmizerConnection $connection): void
    {
        try {
            $connection->deleteRemoteWebhook();
        } catch (Throwable $exception) {
            Log::warning('zapmizer: could not delete the webhook on Zapmizer.', [
                'connection_id' => $connection->getKey(),
                'webhook_id' => $connection->webhook_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    protected function teamConnectedElsewhere(ZapmizerConnection $connection, int $teamId): bool
    {
        return $connection->newQuery()
            ->where('zapmizer_team_id', $teamId)
            ->when($connection->exists, fn ($query) => $query->whereKeyNot($connection->getKey()))
            ->exists();
    }

    protected function validState(mixed $pending, string $state, Model $connectable): bool
    {
        if (!is_array($pending) || !is_string($pending['state'] ?? null) || $state === '') {
            return false;
        }

        try {
            $expiresAt = Carbon::parse($pending['expires_at'] ?? now()->subMinute()->toIso8601String());
        } catch (Throwable) {
            return false;
        }

        return hash_equals($pending['state'], $state)
            && ($pending['connectable'] ?? null) === $this->connectableKey($connectable)
            && $expiresAt->isFuture();
    }

    /**
     * The model being connected on this request. The resolver's return type
     * (`Model&Connectable`) is the contract: a resolver handing back a model
     * without it fails right there, with a TypeError naming the class. A
     * resolver with nothing to connect throws NoConnectableException, which
     * renders itself (403, `no_connectable`) on the JSON endpoints.
     */
    protected function connectable(Request $request): Model&Connectable
    {
        return $this->resolver->resolve($request);
    }

    protected function connectableKey(Model $connectable): string
    {
        return $connectable->getMorphClass() . ':' . $connectable->getKey();
    }

    protected function result(string $status): View
    {
        $messages = [
            'ok' => 'WhatsApp number connected.',
            'denied' => 'Authorization cancelled.',
            'plan_limit' => 'The Zapmizer plan has no room for another WhatsApp number. Free one up on Zapmizer, or upgrade the plan, and try again.',
            'qr_unavailable' => 'The Zapmizer team cannot pair a number by QR code. Check the team settings on Zapmizer and try again.',
            'invalid_state' => 'This connection session is invalid or has expired. Close this window and try again.',
            'exchange_failed' => 'The connection could not be completed. Close this window and try again.',
            'webhook_failed' => 'The number was paired, but the receiver could not be set up. Close this window and try again.',
            'team_already_connected' => 'This Zapmizer account is already connected to another account here. Disconnect it there first, or authorize a different Zapmizer team.',
            'no_connectable' => 'There is nothing to connect on this account. Close this window, sign in again and retry.',
        ];

        return view('zapmizer::connect-result', [
            'status' => $status,
            'message' => $messages[$status],
        ]);
    }
}
