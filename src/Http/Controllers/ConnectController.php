<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Connect\ConnectToken;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\InstanceConnection;
use NotificationChannels\Zapmizer\Connect\InstanceSummary;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\InstanceBootingException;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\InstancePlanLimitException;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use Throwable;

/**
 * Class ConnectController.
 *
 * The hosted connect flow: authorization in a popup (start → callback),
 * instance creation/adoption and pairing polling. Which model is being
 * connected comes from the configured `zapmizer.connect.resolver`; the
 * model implements Contracts\Connectable (through the Connectable trait).
 *
 * Every JSON answer the wizard branches on carries a stable `code`:
 * `reauth_required`, `choice_required`, `booting` (202), `not_connected`
 * (409), `no_instance` (409), `plan_limit` (422), `instance_unavailable`
 * (422), `qr_not_available` (422), `zapmizer_unavailable` (503),
 * `partner_unauthorized` (503), `no_connectable` (403) — the last three
 * rendered by their exceptions.
 */
class ConnectController extends Controller
{
    public const SESSION_KEY = 'zapmizer_connect';

    /**
     * Instance states from which Zapmizer boots the SAME instance again
     * (POST /bot-instances with its id) instead of needing a new one.
     */
    protected const REBOOTABLE_STATES = ['off', 'disconnected'];

    public function __construct(
        protected PartnerClient $partnerClient,
        protected ResolvesConnectable $resolver,
    ) {
    }

    /**
     * The current connection state, so the screen opens knowing where it is.
     */
    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['connection' => $this->connectable($request)->zapmizerConnection]);
    }

    /**
     * Create the authorization session and return the popup URL.
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

        $session = $this->partnerClient->createSession(route('zapmizer.connect.callback'), $state);

        return new JsonResponse($session);
    }

    /**
     * The popup lands here with the code. Renders a page that reports the
     * outcome to the opener through postMessage and closes itself — so
     * nothing here may escape as a 500: an HTML error page in the popup
     * never posts the message, and the wizard waits forever.
     */
    public function callback(Request $request): View
    {
        // pull() guarantees the single use of the state, on error paths too.
        $pending = $request->session()->pull(self::SESSION_KEY);

        if ($request->query('error') === 'access_denied') {
            return $this->result('denied');
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
     * Store the exchanged token on the model's connection.
     *
     * Reconnecting the SAME Zapmizer team updates the row keeping the
     * phone_number and bot_instance_id already paired; connected_at is
     * reset so the wizard knows pairing is pending. A DIFFERENT team gets a
     * clean slate: the old number, instance and webhook belong to the old
     * token, and the old webhook is deleted over there (best-effort). A new
     * connection is born INACTIVE — authorizing does not yield a sender yet.
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

        if ($switchingTeam) {
            $this->forgetRemoteWebhook($connection);
            $connection->forgetPairing();
        }

        $connection->forceFill([
            'api_token' => $token->token,
            'zapmizer_team_id' => $token->teamId,
            'zapmizer_team_name' => $token->teamName,
            'connected_at' => null,
        ]);

        try {
            $connection->save();
        } catch (UniqueConstraintViolationException) {
            // Lost the race with another connectable authorizing the same
            // team between the check above and this insert.
            return $this->result('team_already_connected');
        }

        return $this->result('ok');
    }

    /**
     * Resolve the instance to pair: adopt a chosen one, create a new one, or
     * let the backend decide (reuse the stored one, adopt the only connected
     * one, or create).
     */
    public function instance(Request $request): JsonResponse
    {
        $connection = $this->connectable($request)->zapmizerConnection;

        if ($connection === null || !$connection->hasApiToken()) {
            return new JsonResponse(['code' => 'not_connected'], 409);
        }

        $validated = $request->validate([
            'instance_id' => ['nullable', 'integer', 'min:1'],
            'create' => ['nullable', 'boolean'],
        ]);

        try {
            $client = $connection->instanceClient();

            if ($chosenId = Arr::get($validated, 'instance_id')) {
                return $this->adoptChosenInstance($client, $connection, (int) $chosenId);
            }

            if ($request->boolean('create')) {
                $instanceConnection = $this->provisionNewInstance($client, $connection);
            } else {
                // An instance resolved before (reconnecting after a drop):
                // reuse instead of creating another — avoids a second
                // instance and a false plan_limit.
                $instanceConnection = $this->existingInstanceConnection($client, $connection);

                if ($instanceConnection === null) {
                    $connected = $client->instances(connected: true);

                    // 2+ connected with no explicit choice: adopting the first
                    // would silently pick the wrong number.
                    if (count($connected) >= 2) {
                        return new JsonResponse([
                            'code' => 'choice_required',
                            'instances' => $this->connectedInstanceSummaries($connected, $connection),
                        ]);
                    }

                    $instance = Arr::first($connected);

                    // An adoption that failed (the instance vanished between
                    // the listing and the fetch) equals an empty list: create.
                    $instanceConnection = ($instance ? $this->adoptInstance($client, $connection, (int) $instance['id']) : null)
                        ?? $this->provisionNewInstance($client, $connection);
                }
            }

            if ($instanceConnection === null) {
                return $this->qrNotAvailable();
            }

            // An adopted instance that is already connected never goes through
            // polling — without the sync here the paired number would never
            // become the sending `from`.
            $this->syncConnectedNumber($connection, $instanceConnection);

            return new JsonResponse(['connection' => $instanceConnection]);
        } catch (InstancePlanLimitException $exception) {
            return new JsonResponse(['code' => 'plan_limit', 'message' => $exception->getMessage()], 422);
        } catch (InstanceBootingException $exception) {
            // Zapmizer names the booting instance so nobody creates a second
            // one: stored now, the retry reuses it instead of `create` again.
            if ($exception->instanceId !== null) {
                $this->recordInstance($connection, $exception->instanceId);
            }

            return new JsonResponse(['code' => 'booting'], 202);
        } catch (ZapmizerUnauthorizedException) {
            return new JsonResponse(['code' => 'reauth_required']);
        }
    }

    /**
     * The connected instances, for the sender choice.
     */
    public function instances(Request $request): JsonResponse
    {
        $connection = $this->connectable($request)->zapmizerConnection;

        if ($connection === null || !$connection->hasApiToken()) {
            return new JsonResponse(['code' => 'not_connected'], 409);
        }

        try {
            $summaries = $this->connectedInstanceSummaries(
                $connection->instanceClient()->instances(connected: true),
                $connection,
            );
        } catch (ZapmizerUnauthorizedException) {
            return new JsonResponse(['code' => 'reauth_required']);
        }

        return new JsonResponse(['instances' => $summaries]);
    }

    /**
     * The pairing state of the stored instance — what the wizard polls.
     */
    public function connection(Request $request): JsonResponse
    {
        $connection = $this->connectable($request)->zapmizerConnection;
        $instanceId = $connection?->bot_instance_id;

        if ($connection === null || !$connection->hasApiToken() || blank($instanceId)) {
            return new JsonResponse(['code' => 'no_instance'], 409);
        }

        try {
            $instanceConnection = $connection->instanceClient()->connection((int) $instanceId);
        } catch (ZapmizerUnauthorizedException) {
            return new JsonResponse(['code' => 'reauth_required']);
        } catch (InstanceGoneException) {
            // Deleted on Zapmizer: the stored id stays — restarting the wizard
            // re-resolves it through POST .../instance.
            return new JsonResponse(['code' => 'no_instance'], 409);
        }

        $this->syncConnectedNumber($connection, $instanceConnection);

        return new JsonResponse(['connection' => $instanceConnection]);
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
     * Explicit confirmation of a choice: only records the instance after
     * checking it is still connected — recording first would leave a dead
     * sender stored if it dropped between the list and the click.
     */
    protected function adoptChosenInstance(InstanceClient $client, ZapmizerConnection $connection, int $instanceId): JsonResponse
    {
        try {
            $instanceConnection = $client->connection($instanceId);
        } catch (InstanceGoneException) {
            $instanceConnection = null;
        }

        if ($instanceConnection === null || !$instanceConnection->isConnected()) {
            return new JsonResponse([
                'code' => 'instance_unavailable',
                'message' => 'The chosen number is disconnected. Pick another one or connect a new one.',
                'instances' => $this->connectedInstanceSummaries($client->instances(connected: true), $connection),
            ], 422);
        }

        $this->recordInstance($connection, $instanceId);
        $this->syncConnectedNumber($connection, $instanceConnection);

        return new JsonResponse(['connection' => $instanceConnection]);
    }

    /**
     * Automatic adoption (the only connected one in the listing): confirms
     * the instance still exists before recording it.
     */
    protected function adoptInstance(InstanceClient $client, ZapmizerConnection $connection, int $instanceId): ?InstanceConnection
    {
        try {
            $instanceConnection = $client->connection($instanceId);
        } catch (InstanceGoneException) {
            return null;
        }

        $this->recordInstance($connection, $instanceId);

        return $instanceConnection;
    }

    /**
     * Create an instance and fetch its connection. Null when the connection
     * endpoint refuses the instance right after creating it — Zapmizer
     * answers 404 there for a team without the QR-code feature, and
     * treating that as "gone" would create yet another instance per retry.
     */
    protected function provisionNewInstance(InstanceClient $client, ZapmizerConnection $connection): ?InstanceConnection
    {
        $instance = $client->createInstance();

        // Recorded before the fetch on purpose: if the fresh instance is
        // still booting (423), the next poll reuses the id instead of
        // creating another.
        $this->recordInstance($connection, (int) $instance['id']);

        try {
            return $client->connection((int) $instance['id']);
        } catch (InstanceGoneException $exception) {
            Log::warning('zapmizer: the connection of a just-created instance is unavailable.', [
                'connection_id' => $connection->getKey(),
                'bot_instance_id' => $instance['id'],
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function qrNotAvailable(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'qr_not_available',
            'message' => 'The instance was created but its QR code cannot be fetched. Check that the Zapmizer team has QR-code connections enabled.',
        ], 422);
    }

    protected function recordInstance(ZapmizerConnection $connection, int $instanceId): void
    {
        $connection->forceFill(['bot_instance_id' => $instanceId])->save();
    }

    /**
     * @param array<int, array<string, mixed>> $instances
     * @return array<int, InstanceSummary>
     */
    protected function connectedInstanceSummaries(array $instances, ZapmizerConnection $connection): array
    {
        $currentId = $connection->bot_instance_id;

        return collect($instances)
            ->filter(fn (array $item) => filled(Arr::get($item, 'client.cid_formatted')))
            ->map(fn (array $item) => InstanceSummary::fromArray($item, $currentId === null ? null : (int) $currentId))
            ->values()
            ->all();
    }

    /**
     * Activate the connection when the number pairs — and only then. Runs on
     * a polling loop of a few seconds, so it only writes when something
     * really changed.
     */
    protected function syncConnectedNumber(ZapmizerConnection $connection, InstanceConnection $instanceConnection): void
    {
        if (!$instanceConnection->isConnected()) {
            return;
        }

        // Same number is only a no-op when connected_at already exists: a
        // reconnection resets connected_at keeping phone_number, and without
        // this second leg re-pairing with the SAME number would leave the
        // wizard mid-flow forever.
        if ($connection->phone_number === $instanceConnection->number && $connection->connected_at !== null) {
            $this->ensureWebhookRegistered($connection);

            return;
        }

        $connection->forceFill([
            'phone_number' => $instanceConnection->number,
            'connected_at' => now(),
            'is_active' => true,
        ])->save();

        $this->ensureWebhookRegistered($connection);
    }

    /**
     * The receiver must be registered on Zapmizer's side, and the secret only
     * comes out in the creation response. Failing here does not break the
     * wizard: the number is paired, and registration is retried on the next
     * poll.
     */
    protected function ensureWebhookRegistered(ZapmizerConnection $connection): void
    {
        try {
            $connection->registerWebhook();
        } catch (Throwable $exception) {
            Log::warning('zapmizer: could not register the webhook.', [
                'connection_id' => $connection->getKey(),
                'exception' => $exception->getMessage(),
            ]);
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

    /**
     * The stored instance, ready to pair. An instance that dropped
     * (`disconnected`: the phone went away) or was turned off is booted
     * again — Zapmizer only shows a new QR code after that, and it is the
     * same POST as creating, with the id in the body. Null when there is
     * nothing stored or Zapmizer no longer knows the id.
     *
     * @throws ZapmizerConnectException
     */
    protected function existingInstanceConnection(InstanceClient $client, ZapmizerConnection $connection): ?InstanceConnection
    {
        $instanceId = $connection->bot_instance_id;

        if (blank($instanceId)) {
            return null;
        }

        try {
            $instanceConnection = $client->connection((int) $instanceId);

            if (!in_array($instanceConnection->state, static::REBOOTABLE_STATES, true)) {
                return $instanceConnection;
            }

            $client->createInstance((int) $instanceId);

            return $client->connection((int) $instanceId);
        } catch (InstanceGoneException) {
            return null;
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
            'ok' => 'WhatsApp account connected.',
            'denied' => 'Authorization cancelled.',
            'invalid_state' => 'This connection session is invalid or has expired. Close this window and try again.',
            'exchange_failed' => 'The connection could not be completed. Close this window and try again.',
            'team_already_connected' => 'This Zapmizer account is already connected to another account here. Disconnect it there first, or authorize a different Zapmizer team.',
            'no_connectable' => 'There is nothing to connect on this account. Close this window, sign in again and retry.',
        ];

        return view('zapmizer::connect-result', [
            'status' => $status,
            'message' => $messages[$status],
        ]);
    }
}
