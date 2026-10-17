<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\ConnectToken;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Events\NumberConnected;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Http\Controllers\Concerns\HandlesPopupState;
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
 * `ok`, `denied`, `plan_limit`, `subscription_required`, `invalid_state`,
 * `exchange_failed`, `rate_limited`, `webhook_failed`,
 * `team_already_connected`, `no_connectable`. The `state` is checked before
 * anything the popup brings back, `?error=` included. A 429 on the exchange
 * answers a page that refreshes after `Retry-After` and tries the same code
 * again, up to 5 attempts within 240 seconds of the first. The JSON endpoints answer `zapmizer_unavailable`
 * (503), `partner_unauthorized` (503) and `no_connectable` (403) themselves:
 * the package's exceptions do not render.
 */
class ConnectController extends Controller
{
    use HandlesPopupState;

    public const SESSION_KEY = 'zapmizer_connect';

    private const EXCHANGE_ATTEMPTS = 5;

    private const EXCHANGE_WINDOW = 240;

    /**
     * What the hosted page reports in `?error=` when it ends without a code,
     * and the postMessage status each one becomes.
     */
    protected const POPUP_ERRORS = [
        'access_denied' => 'denied',
        'plan_limit' => 'plan_limit',
        'subscription_required' => 'subscription_required',
    ];

    /**
     * The webhook key of the pending state, read on the callback.
     */
    protected ?string $pendingWebhookKey = null;

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
        try {
            $connection = $this->connectable($request)->zapmizerConnection;
        } catch (NoConnectableException) {
            return $this->code('no_connectable', 403);
        }

        if ($connection === null || !$request->boolean('live')) {
            return new JsonResponse(['connection' => $connection]);
        }

        return new JsonResponse(['connection' => $connection->toArray() + $this->liveState($connection)]);
    }

    /**
     * Create the authorization session and return the popup URL. The
     * webhook route goes along: Zapmizer registers it on the team once the
     * number pairs, and the id/secret come back with the token. So do the
     * model's `external_id` (zapmizerExternalId(), which keys the
     * subscription and the checkout) and the state's TTL as `expires_in`.
     * An invalid `external_id` is a programming error: its
     * InvalidArgumentException is not caught.
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $connectable = $this->connectable($request);
            $key = $this->webhookKeyFor($connectable);
            $pending = $this->issueState($request, self::SESSION_KEY, $connectable, $key === null ? [] : ['webhook_key' => $key]);

            $session = $this->partnerClient->createSession(
                redirectUri: route('zapmizer.connect.callback'),
                state: $pending['state'],
                webhookUrl: $this->webhookUrl($key),
                expiresIn: $this->stateTtlMinutes() * 60,
                externalId: $connectable->zapmizerExternalId(),
            );
        } catch (PartnerCredentialsException $exception) {
            report($exception);

            return $this->code('partner_unauthorized', 503);
        } catch (ZapmizerUnavailableException | ZapmizerApiException $exception) {
            report($exception);

            return $this->code('zapmizer_unavailable', 503);
        } catch (NoConnectableException) {
            return $this->code('no_connectable', 403);
        } catch (ZapmizerConnectException $exception) {
            report($exception);

            return $this->code('zapmizer_unavailable', 503);
        }

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
        $pending = $this->takePending($request, self::SESSION_KEY);

        try {
            $connectable = $this->connectable($request);
        } catch (NoConnectableException) {
            return $this->result('no_connectable');
        }

        if (!$this->validState($pending, $this->queryString($request, 'state'), $connectable)) {
            return $this->result('invalid_state');
        }

        if (isset($pending['code_hash']) && !hash_equals((string) $pending['code_hash'], hash('sha256', $this->queryString($request, 'code')))) {
            return $this->result('invalid_state');
        }

        $this->pendingWebhookKey = is_string($pending['webhook_key'] ?? null) ? $pending['webhook_key'] : null;

        if (($error = $this->errorParam($request)) !== null) {
            return $this->result($this->popupStatus($error));
        }

        if (($code = $this->queryString($request, 'code')) === '') {
            return $this->result('exchange_failed');
        }

        try {
            $token = $this->partnerClient->exchangeCode($code);

            if ($token === null) {
                return $this->result('exchange_failed');
            }

            if (!$token->hasNumber()) {
                Log::warning('zapmizer: the token came without a number.', ['team_id' => $token->teamId]);
                $this->revokeOrphanToken($token);

                return $this->result('exchange_failed');
            }

            return $this->storeToken($connectable, $token);
        } catch (ZapmizerRateLimitedException $exception) {
            return $this->waitForExchange($request, $pending, $exception);
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
     * A 429 does not spend the code: the popup waits `Retry-After` (10
     * seconds without a usable one) and comes back with the same code and
     * state, which go back into the session with the attempt count. It
     * gives up with `rate_limited` after 5 attempts, or when the wait would
     * pass 240 seconds from the first attempt or the state's expiry.
     */
    private function waitForExchange(Request $request, array $pending, ZapmizerRateLimitedException $exception): View
    {
        $now = now()->getTimestamp();
        $seenAt = (int) ($pending['code_seen_at'] ?? $now);
        $attempt = (int) ($pending['exchange_attempts'] ?? 0) + 1;
        $wait = ($exception->retryAfter() ?? 9) + 1;
        $deadline = min($seenAt + self::EXCHANGE_WINDOW, Carbon::parse($pending['expires_at'])->getTimestamp());

        if ($attempt > self::EXCHANGE_ATTEMPTS || $now + $wait > $deadline) {
            return $this->result('rate_limited');
        }

        $request->session()->put(self::SESSION_KEY, array_merge($pending, [
            'code_seen_at' => $seenAt,
            'exchange_attempts' => $attempt,
            'code_hash' => hash('sha256', $this->queryString($request, 'code')),
        ]));

        return view('zapmizer::connect-wait', [
            'seconds' => $wait,
            'message' => "Zapmizer is busy. This window tries again in {$wait} seconds.",
        ]);
    }

    /**
     * Store everything the exchange brought on the model's connection. The
     * hosted page paired the number before handing out the code, so the
     * connection is born ACTIVE. Nothing here calls `/webhooks`: the connect
     * token cannot (403 `missing_ability`).
     *
     * A DIFFERENT team than the one stored gets a clean slate first: the old
     * number, instance and webhook belong to the old token. The SAME team
     * keeps its stored webhook secret when Zapmizer reused the webhook (same
     * id, no secret in the answer). Without any secret the connection stays
     * active — it sends — and the popup says `webhook_failed`: it cannot
     * verify what it receives until it disconnects and connects again.
     *
     * A stored value that no longer decrypts (APP_KEY changed) counts as
     * absent, with a warning, and is overwritten. With
     * `zapmizer.connect.revoke_previous_token` on, the token the row held
     * before is revoked once the new one is saved. Then NumberConnected
     * fires; a listener that throws is reported and the status stays.
     */
    protected function storeToken(Model&Connectable $connectable, ConnectToken $token): View
    {
        $connection = $connectable->zapmizerConnectionOrNew();

        // One connectable per Zapmizer team: two of them would register two
        // webhooks and every message would fire on both tenants.
        if ($this->teamConnectedElsewhere($connection, $token->teamId)) {
            $this->revokeOrphanToken($token);

            return $this->result('team_already_connected');
        }

        $previousToken = $this->shouldRevokePreviousToken() && $connection->exists && $connection->hasApiToken()
            ? $this->readEncrypted($connection, 'api_token', 'zapmizer: could not read the previous token to revoke it.')
            : null;

        $switchingTeam = $connection->exists
            && $connection->zapmizer_team_id !== null
            && $connection->zapmizer_team_id !== $token->teamId;

        if ($switchingTeam) {
            $connection->forgetPairing();
        }

        $reusedWebhook = $token->needsWebhookSecret()
            && $connection->webhook_id === $token->webhookId
            && filled($this->readEncrypted($connection, 'webhook_secret', 'zapmizer: unreadable webhook secret.'));

        $this->ensureWritable($connection, 'api_token');

        if (!$reusedWebhook && $token->webhookSecret !== null) {
            $this->ensureWritable($connection, 'webhook_secret');
        }

        $connection->forceFill([
            'api_token' => $token->token,
            'zapmizer_team_id' => $token->teamId,
            'zapmizer_team_name' => $token->teamName,
            'phone_number' => $token->phoneNumber,
            'bot_instance_id' => $token->botInstanceId,
            'connected_at' => now(),
            'webhook_id' => $token->webhookId,
            'is_active' => true,
        ]);

        if (!$reusedWebhook) {
            $connection->forceFill([
                'webhook_secret' => $token->webhookSecret,
                'webhook_previous_secret' => null,
            ]);
        }

        try {
            $connection->save();
        } catch (QueryException $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Lost the race with another connectable authorizing the same
            // team between the check above and this insert.
            $this->revokeOrphanToken($token);

            return $this->result('team_already_connected');
        }

        $status = $token->needsWebhookSecret() && !$reusedWebhook ? 'webhook_failed' : 'ok';

        if ($status === 'webhook_failed') {
            Log::warning('zapmizer: connected without a webhook secret.', [
                'connection_id' => $connection->getKey(),
                'webhook_id' => $connection->webhook_id,
            ]);
        }

        if ($previousToken !== null && !hash_equals($previousToken, $token->token)) {
            $this->revokePreviousToken($connection, $previousToken);
        }

        rescue(fn () => event(new NumberConnected($connectable, $connection)), report: true);

        return $this->result($status);
    }

    private function shouldRevokePreviousToken(): bool
    {
        return filter_var(config('zapmizer.connect.revoke_previous_token', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The decrypted `$column`; `null` when it does not decrypt (APP_KEY
     * changed), with `$warning` in the log.
     */
    private function readEncrypted(ZapmizerConnection $connection, string $column, string $warning): ?string
    {
        try {
            $value = $connection->{$column};
        } catch (DecryptException) {
            Log::warning($warning, ['connection_id' => $connection->getKey()]);

            return null;
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * Before a new value is written to an encrypted `$column`: Eloquent's
     * dirty check decrypts the ORIGINAL value, and one that no longer
     * decrypts would throw on save. Such a column is reset to `null` — the
     * attribute and its original — and the new value written over it. The
     * raw original decides, not the current attribute: forgetPairing()
     * nulls the attribute but not the original.
     */
    private function ensureWritable(ZapmizerConnection $connection, string $column): void
    {
        $raw = $connection->getRawOriginal($column);

        if ($raw === null) {
            return;
        }

        try {
            $connection->fromEncryptedString($raw);
        } catch (DecryptException) {
            $connection->forceFill([$column => null]);
            $connection->syncOriginalAttribute($column);

            Log::warning('zapmizer: unreadable stored value, overwritten.', [
                'connection_id' => $connection->getKey(),
                'column' => $column,
            ]);
        }
    }

    private function revokePreviousToken(ZapmizerConnection $connection, string $previous): void
    {
        $exception = $this->tryRevoke($previous);

        if ($exception !== null) {
            Log::warning('zapmizer: the previous token was not revoked.', ['connection_id' => $connection->getKey()] + $this->failureContext($exception));
        }
    }

    /**
     * Disconnect the model from Zapmizer: the token is revoked
     * (`DELETE /connect/token`, best-effort — a failure is a warning and the
     * local row goes anyway) and the local row with its credentials dies.
     * Zapmizer drops the webhook the connect created once no other live
     * grant uses the same URL.
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $connection = $this->connectable($request)->zapmizerConnection;
        } catch (NoConnectableException) {
            return $this->code('no_connectable', 403);
        }

        if ($connection !== null) {
            $token = $connection->hasApiToken()
                ? $this->readEncrypted($connection, 'api_token', 'zapmizer: could not read the token to revoke it.')
                : null;

            if ($token !== null) {
                $this->revokeOnDestroy($connection, $token);
            }

            $connection->delete();
        }

        return new JsonResponse(['status' => 'disconnected']);
    }

    private function revokeOnDestroy(ZapmizerConnection $connection, string $token): void
    {
        $exception = $this->tryRevoke($token);

        if ($exception === null) {
            return;
        }

        $stillValid = $exception instanceof ZapmizerApiException && $exception->status() === 404;

        Log::warning(
            $stillValid ? 'zapmizer: the token was not revoked and is still valid.' : 'zapmizer: the token was not revoked.',
            ['connection_id' => $connection->getKey()] + $this->failureContext($exception),
        );
    }

    /**
     * Revoke a token the exchange handed out but nothing stored (another
     * connectable holds the team, or no number came with it): kept, it
     * would stay valid with no one holding it. One attempt; a failure is a
     * warning and never changes the popup's status.
     */
    protected function revokeOrphanToken(ConnectToken $token): void
    {
        $exception = $this->tryRevoke($token->token);

        if ($exception !== null) {
            Log::warning('zapmizer: the orphan token was not revoked.', ['team_id' => $token->teamId] + $this->failureContext($exception));
        }
    }

    /**
     * One `DELETE /connect/token` with `$token`; the failure, if any.
     */
    private function tryRevoke(string $token): ?Throwable
    {
        try {
            app(InstanceClient::class, [
                'api_token' => $token,
                'api_version' => config('zapmizer.api_version'),
            ])->revokeToken();

            return null;
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    /**
     * @return array{status?: int, exception: string}
     */
    private function failureContext(Throwable $exception): array
    {
        $context = $exception instanceof ZapmizerApiException ? ['status' => $exception->status()] : [];

        return $context + ['exception' => $exception->getMessage()];
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
        } catch (DecryptException) {
            Log::warning('zapmizer: could not read the token to query the instance.', ['connection_id' => $connection->getKey()]);

            return ['state' => 'reauth_required', 'is_online' => false];
        } catch (ZapmizerUnauthorizedException) {
            return ['state' => 'reauth_required', 'is_online' => false];
        } catch (InstanceGoneException) {
            return ['state' => 'instance_gone', 'is_online' => false];
        } catch (ZapmizerUnavailableException | ZapmizerConnectException) {
            return ['state' => 'zapmizer_unavailable', 'is_online' => false];
        }
    }

    /**
     * The key of the webhook this connectable's session registers; `null`
     * is the package's single webhook route.
     */
    protected function webhookKeyFor(Model&Connectable $connectable): ?string
    {
        return null;
    }

    /**
     * The webhook URL sent with the session for `$key`.
     */
    protected function webhookUrl(?string $key): string
    {
        return route('zapmizer.webhook');
    }

    protected function teamConnectedElsewhere(ZapmizerConnection $connection, int $teamId): bool
    {
        return $connection->newQuery()
            ->where('zapmizer_team_id', $teamId)
            ->when($connection->exists, fn ($query) => $query->whereKeyNot($connection->getKey()))
            ->exists();
    }

    protected function isUniqueViolation(QueryException $exception): bool
    {
        $message = ($exception->getPrevious() ?? $exception)->getMessage();

        return $exception instanceof UniqueConstraintViolationException
            || $exception->getCode() === '23505'
            || preg_match('#Integrity constraint violation: 1062|UNIQUE constraint failed: |column(s)? .* (is|are) not unique|Cannot insert duplicate key(?: row)? in object#i', $message) === 1;
    }

    /**
     * The status for the hosted page's `?error=`. An unknown one (or a
     * value that is not a string) is logged and becomes `exchange_failed`.
     */
    protected function popupStatus(string $error): string
    {
        if (isset(static::POPUP_ERRORS[$error])) {
            return static::POPUP_ERRORS[$error];
        }

        Log::warning('zapmizer: unknown connect error.', ['error' => mb_substr($error, 0, 100)]);

        return 'exchange_failed';
    }

    protected function result(string $status): View
    {
        $messages = [
            'ok' => 'WhatsApp number connected.',
            'denied' => 'Authorization cancelled.',
            'plan_limit' => 'The Zapmizer plan has no room for another WhatsApp number. Free one up on Zapmizer, or upgrade the plan, and try again.',
            'subscription_required' => 'Your Zapmizer account was linked, but it has no active subscription. Connect again to subscribe.',
            'invalid_state' => 'This connection session is invalid or has expired. Close this window and try again.',
            'exchange_failed' => 'The connection could not be completed. Close this window and try again.',
            'rate_limited' => 'Zapmizer is busy right now. Close this window and connect again in a minute.',
            'webhook_failed' => "Connected and sending, but incoming messages can't be verified. Disconnect and connect again to receive.",
            'team_already_connected' => 'This Zapmizer account is already connected to another account here. Disconnect it there first, or authorize a different Zapmizer team.',
            'no_connectable' => 'There is nothing to connect on this account. Close this window, sign in again and retry.',
        ];

        return view('zapmizer::connect-result', [
            'status' => $status,
            'message' => $messages[$status],
        ]);
    }
}
