<?php

namespace NotificationChannels\Zapmizer\Webhook;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drops the repeats of a delivery by the envelope `id` (Zapmizer retries
 * with the same one), when `zapmizer.webhook.deduplicate` is on. Off by
 * default.
 *
 * Two phases. `claim()` reserves the id atomically (`Cache::add`) for
 * PROCESSING_TTL seconds before anything runs; `confirm()`, after an answer
 * that is not 5xx, keeps it for the whole `deduplicate_ttl`; `release()`,
 * after an exception or a 5xx, frees it so Zapmizer's retry goes through. A
 * worker killed mid-delivery leaves only the short reservation behind. A
 * retry that arrives while the first attempt still runs gets 200 and fires
 * nothing — if that first attempt then fails, the event is lost.
 *
 * A delivery without an `id` is always processed, and so are the unsigned
 * `verify_number.*` events: anyone can POST one, and its id must not burn
 * the id of a real event.
 *
 * Best effort: with the cache down (or a store that is not configured) the
 * delivery is processed and a warning is logged — answering 5xx would only
 * make Zapmizer retry into the same failure.
 */
class Deduplicator
{
    public const KEY_PREFIX = 'zapmizer:webhook:';

    /**
     * How long a claim lasts while the delivery is being processed, in
     * seconds.
     */
    public const PROCESSING_TTL = 120;

    public const DEFAULT_TTL = 21600;

    /**
     * The claim could not reach the cache: confirm and release skip it, so
     * a delivery logs a single warning.
     */
    private bool $unavailable = false;

    /**
     * Claim the delivery. False when it was already processed.
     *
     * @param array<string, mixed> $payload
     */
    public function claim(array $payload): bool
    {
        $id = $this->idOf($payload);

        if ($id === null) {
            return true;
        }

        try {
            return $this->store()->add($this->key($id), true, min(self::PROCESSING_TTL, $this->ttl()));
        } catch (Throwable $exception) {
            $this->unavailable = true;
            $this->warn($id, $exception);

            return true;
        }
    }

    /**
     * Keep a processed delivery's id for the whole TTL.
     *
     * @param array<string, mixed> $payload
     */
    public function confirm(array $payload): void
    {
        $id = $this->idOf($payload);

        if ($id === null || $this->unavailable) {
            return;
        }

        try {
            $this->store()->put($this->key($id), true, $this->ttl());
        } catch (Throwable $exception) {
            $this->warn($id, $exception);
        }
    }

    /**
     * Release a claim, so a retry of a failed delivery is processed.
     *
     * @param array<string, mixed> $payload
     */
    public function release(array $payload): void
    {
        $id = $this->idOf($payload);

        if ($id === null || $this->unavailable) {
            return;
        }

        try {
            $this->store()->forget($this->key($id));
        } catch (Throwable $exception) {
            $this->warn($id, $exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function idOf(array $payload): ?string
    {
        if (!config('zapmizer.webhook.deduplicate', false)) {
            return null;
        }

        $name = $payload['name'] ?? null;

        if (is_string($name) && Str::startsWith($name, 'verify_number.')) {
            return null;
        }

        return Fields::identifier($payload, 'id');
    }

    protected function key(string $id): string
    {
        return self::KEY_PREFIX . sha1($id);
    }

    protected function ttl(): int
    {
        $ttl = config('zapmizer.webhook.deduplicate_ttl');

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : self::DEFAULT_TTL;
    }

    protected function store(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('zapmizer.webhook.deduplicate_store'));
    }

    protected function warn(string $id, Throwable $exception): void
    {
        Log::warning('zapmizer: webhook deduplication unavailable, delivery processed.', [
            'id' => $id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
