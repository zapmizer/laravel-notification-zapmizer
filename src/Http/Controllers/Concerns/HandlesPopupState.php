<?php

namespace NotificationChannels\Zapmizer\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use Throwable;

/**
 * The popup's `state`, kept in the session between the start and the
 * callback. Uses the controller's `$resolver`.
 */
trait HandlesPopupState
{
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
     * the JSON endpoints answer with 403 `no_connectable`.
     */
    protected function connectable(Request $request): Model&Connectable
    {
        return $this->resolver->resolve($request);
    }

    protected function code(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code], $status);
    }

    protected function connectableKey(Model $connectable): string
    {
        return $connectable->getMorphClass() . ':' . $connectable->getKey();
    }

    /**
     * Store a fresh pending state under `$key` and return it.
     *
     * @return array<string, mixed>
     */
    private function issueState(Request $request, string $key, Model $connectable, array $extra = []): array
    {
        $pending = [
            'state' => Str::random(40),
            'connectable' => $this->connectableKey($connectable),
            'expires_at' => now()->addMinutes($this->stateTtlMinutes())->toIso8601String(),
        ] + $extra;

        $request->session()->put($key, $pending);

        return $pending;
    }

    /**
     * The pending state under `$key`, taken out of the session only when
     * the query's `state` matches it: a callback carrying another tab's (or
     * a forged) state leaves the pending one where it is.
     *
     * @return array<string, mixed>|null
     */
    private function takePending(Request $request, string $key): ?array
    {
        $pending = $request->session()->get($key);
        $state = $this->queryString($request, 'state');

        if (!is_array($pending) || !is_string($pending['state'] ?? null) || $state === '' || !hash_equals($pending['state'], $state)) {
            return null;
        }

        $request->session()->forget($key);

        return $pending;
    }

    /**
     * A query parameter as a string; anything else (absent, an array) is
     * `''`.
     */
    private function queryString(Request $request, string $name): string
    {
        $value = $request->query($name);

        return is_string($value) ? $value : '';
    }

    /**
     * The popup's `?error=`: `null` when absent or empty, the string, or
     * `'(array)'` for a value that is not one.
     */
    private function errorParam(Request $request): ?string
    {
        $error = $request->query('error');

        if ($error === null || $error === '') {
            return null;
        }

        return is_string($error) ? $error : '(array)';
    }

    private function stateTtlMinutes(): int
    {
        $minutes = config('zapmizer.connect.state_ttl_minutes');

        return is_numeric($minutes) && (int) $minutes >= 1 ? (int) $minutes : 30;
    }
}
