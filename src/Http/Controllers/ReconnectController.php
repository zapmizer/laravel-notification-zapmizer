<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\ReconnectResult;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Events\ReconnectRefused;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Http\Controllers\Concerns\HandlesPopupState;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use Throwable;

/**
 * Class ReconnectController.
 *
 * Brings the model's paired number back online with the token it already
 * holds (`POST /bot-instances/{id}/reconnect`). `start` answers 200 with
 * `status`: `online`, `starting`, `needs_client` (open `url` in a popup)
 * or `refused` (402, with `code` and `message`); the errors are 409
 * `no_instance`, 409 `reauth_required`, 503 `zapmizer_unavailable` and 403
 * `no_connectable`. The popup comes back to `callback`, which posts
 * `source: 'zapmizer-reconnect'` with `reconnected`, `denied`, `plan_limit`,
 * `subscription_required`, `invalid_state`, `failed` or `no_connectable`.
 * Nothing is stored: the token does not change on a reconnect.
 */
class ReconnectController extends Controller
{
    use HandlesPopupState;

    public const SESSION_KEY = 'zapmizer_reconnect';

    private const ERRORS = [
        'access_denied' => 'denied',
        'plan_limit' => 'plan_limit',
        'subscription_required' => 'subscription_required',
    ];

    private const REFUSALS = [
        'plan_limit' => 'The Zapmizer plan has no room for another number online.',
        'subscription_required' => 'The Zapmizer account has no active subscription.',
    ];

    public function __construct(protected ResolvesConnectable $resolver)
    {
    }

    /**
     * Ask Zapmizer to reconnect the paired instance. Only `needs_client`
     * keeps the state in the session: the popup comes back with it.
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $connectable = $this->connectable($request);
        } catch (NoConnectableException) {
            return $this->code('no_connectable', 403);
        }

        $connection = $connectable->zapmizerConnection;

        if ($connection === null || !$connection->hasApiToken() || $connection->bot_instance_id === null) {
            return $this->code('no_instance', 409);
        }

        try {
            $token = (string) $connection->api_token;
        } catch (DecryptException) {
            Log::warning('zapmizer: could not read the token to reconnect.', ['connection_id' => $connection->getKey()]);

            return $this->code('reauth_required', 409);
        }

        $pending = $this->issueState($request, self::SESSION_KEY, $connectable);

        try {
            $result = app(InstanceClient::class, [
                'api_token' => $token,
                'api_version' => config('zapmizer.api_version'),
            ])->reconnect(
                (int) $connection->bot_instance_id,
                route('zapmizer.connect.reconnect.callback'),
                $pending['state'],
                $this->stateTtlMinutes() * 60,
            );
        } catch (Throwable $exception) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->failure($exception, $connectable, $connection);
        }

        if (!$result->needsClient()) {
            $request->session()->forget(self::SESSION_KEY);

            return new JsonResponse(['status' => $result->status]);
        }

        return new JsonResponse([
            'status' => ReconnectResult::NEEDS_CLIENT,
            'url' => $result->url,
            'expires_at' => $result->expiresAt?->toIso8601String(),
        ]);
    }

    /**
     * The popup lands here with `status=reconnected` or `error=…`, and the
     * state. Renders a page that posts the outcome to the opener; nothing
     * here may escape as a 500.
     */
    public function callback(Request $request): View
    {
        try {
            $pending = $this->takePending($request, self::SESSION_KEY);

            try {
                $connectable = $this->connectable($request);
            } catch (NoConnectableException) {
                return $this->result('no_connectable');
            }

            if (!$this->validState($pending, $this->queryString($request, 'state'), $connectable)) {
                return $this->result('invalid_state');
            }

            if (($error = $this->errorParam($request)) !== null) {
                if (!isset(self::ERRORS[$error])) {
                    Log::warning('zapmizer: unknown reconnect error.', ['error' => mb_substr($error, 0, 100)]);
                }

                return $this->result(self::ERRORS[$error] ?? 'failed');
            }

            return $this->result($this->queryString($request, 'status') === 'reconnected' ? 'reconnected' : 'failed');
        } catch (Throwable $exception) {
            report($exception);

            return $this->result('failed');
        }
    }

    protected function result(string $status): View
    {
        $messages = [
            'reconnected' => 'WhatsApp number reconnected.',
            'denied' => 'Reconnection cancelled.',
            'plan_limit' => 'The Zapmizer plan has no room for another WhatsApp number. Free one up on Zapmizer, or upgrade the plan, and try again.',
            'subscription_required' => 'Your Zapmizer account has no active subscription. Subscribe on Zapmizer and try again.',
            'invalid_state' => 'This connection session is invalid or has expired. Close this window and try again.',
            'failed' => 'The number could not be reconnected. Close this window and try again.',
            'no_connectable' => 'There is nothing to connect on this account. Close this window, sign in again and retry.',
        ];

        return view('zapmizer::reconnect-result', [
            'status' => $status,
            'message' => $messages[$status],
        ]);
    }

    /**
     * The answer to a reconnect that threw: a 402 with a code is a refusal
     * the front shows, a dead token asks for a new connect, a gone instance
     * has nothing to reconnect, and the rest is Zapmizer being unavailable.
     */
    private function failure(Throwable $exception, Model&Connectable $connectable, ZapmizerConnection $connection): JsonResponse
    {
        if ($exception instanceof ZapmizerUnauthorizedException) {
            return $this->code('reauth_required', 409);
        }

        if ($exception instanceof InstanceGoneException) {
            return $this->code('no_instance', 409);
        }

        if ($exception instanceof ZapmizerApiException && $exception->status() === 402 && is_string($code = $exception->error())) {
            $message = $exception->payload()['message'] ?? null;
            $message = is_string($message) ? $message : null;

            rescue(fn () => event(new ReconnectRefused($connectable, $connection, $code, $message)), report: true);

            return new JsonResponse([
                'status' => 'refused',
                'code' => $code,
                'message' => $message ?? self::REFUSALS[$code] ?? 'Zapmizer refused to reconnect this number.',
            ]);
        }

        if ($exception instanceof ZapmizerApiException && $exception->status() === 403) {
            Log::warning('zapmizer: reconnect refused.', [
                'connection_id' => $connection->getKey(),
                'status' => 403,
                'error' => $exception->error(),
            ]);

            return $this->code('reauth_required', 409);
        }

        report($exception);

        return $this->code('zapmizer_unavailable', 503);
    }
}
