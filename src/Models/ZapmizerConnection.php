<?php

namespace NotificationChannels\Zapmizer\Models;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\MediaDownload;
use NotificationChannels\Zapmizer\Connect\WebhookRegistration;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Support\PhoneNumber;
use NotificationChannels\Zapmizer\Zapmizer;
use NotificationChannels\Zapmizer\ZapmizerMessage;
use Throwable;

/**
 * Class ZapmizerConnection.
 *
 * The Zapmizer account authorized by a Connectable model — one per model.
 * Extend it and point `zapmizer.models.connection` at your subclass to
 * customize.
 *
 * The three credentials are `encrypted` casts and `hidden` from
 * serialization — deliberately with no accessor on top of them: an
 * `Attribute` accessor for `api_token` would win over the cast and return
 * the ciphertext. For screens there is `api_token_masked`, a separate
 * attribute.
 *
 * `$casts` is a property, not the `casts()` method: the method only exists
 * on Laravel 11+, and on 10 the credentials would be written in the clear.
 */
class ZapmizerConnection extends Model
{
    protected $table = 'zapmizer_connections';

    protected $guarded = [];

    protected $hidden = [
        'api_token',
        'webhook_secret',
        'webhook_previous_secret',
    ];

    protected $appends = [
        'api_token_masked',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'webhook_previous_secret' => 'encrypted',
        'zapmizer_team_id' => 'integer',
        'bot_instance_id' => 'integer',
        'webhook_id' => 'integer',
        'connected_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * The model that owns the connection (team, user, ...).
     */
    public function connectable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeWithWebhookSecret(Builder $query): Builder
    {
        return $query->whereNotNull('webhook_secret');
    }

    /**
     * Last 4 characters of the token, so a screen can say "has credentials"
     * without leaking them. A token that no longer decrypts (APP_KEY
     * changed) shows as `••••`: serializing the model — every `toArray()`,
     * every Inertia prop — must not throw because of it.
     */
    protected function apiTokenMasked(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (!$this->hasApiToken()) {
                return null;
            }

            try {
                return '••••' . Str::substr((string) $this->api_token, -4);
            } catch (Throwable) {
                return '••••';
            }
        });
    }

    /**
     * Whether a token is stored — checked on the raw (encrypted) attribute,
     * so it never decrypts.
     */
    public function hasApiToken(): bool
    {
        return filled($this->getAttributes()['api_token'] ?? null);
    }

    /**
     * Active with a paired number: ready to send and receive.
     */
    public function isConnected(): bool
    {
        return $this->is_active && filled($this->phone_number) && $this->hasApiToken();
    }

    /**
     * The secrets that may have signed an in-flight delivery: the current
     * one and, during a rotation, the previous one.
     *
     * @return array<int, string>
     */
    public function signingSecrets(): array
    {
        return array_values(array_filter([
            $this->webhook_secret,
            $this->webhook_previous_secret,
        ], fn (?string $secret) => filled($secret)));
    }

    /**
     * Messages client authenticated as this connection.
     */
    public function zapmizer(): Zapmizer
    {
        return app(Zapmizer::class, [
            'api_token' => $this->api_token,
            'api_version' => config('zapmizer.api_version'),
        ]);
    }

    /**
     * Instance-state/webhook client authenticated as this connection. The token
     * is mandatory: without it the client would fall back to the
     * single-tenant credentials and act in another account's name.
     *
     * @throws ZapmizerUnauthorizedException
     */
    public function instanceClient(): InstanceClient
    {
        if (!$this->hasApiToken()) {
            throw new ZapmizerUnauthorizedException('There is no Zapmizer token for this connection.');
        }

        return app(InstanceClient::class, [
            'api_token' => $this->api_token,
            'api_version' => config('zapmizer.api_version'),
        ]);
    }

    /**
     * A message from this connection's paired number — the analogue of
     * Cashier's `$user->charge()`.
     *
     * @throws ZapmizerConnectException
     */
    public function message(string $to): ZapmizerMessage
    {
        if (!$this->isConnected()) {
            throw ZapmizerConnectException::notConnected();
        }

        return ZapmizerMessage::create(
            from: PhoneNumber::digits($this->phone_number),
            to: PhoneNumber::digits($to),
            zapmizer: $this->zapmizer(),
        );
    }

    /**
     * The media of a message this connection received. One request, no
     * waiting: the answer is `attached`, `downloading` (the webhook fired
     * before the bot finished the download — ask again later) or
     * `unavailable` (it will never come). Needs the paired instance: a
     * connection that lost it has nothing to ask Zapmizer about.
     *
     * @throws ZapmizerConnectException
     */
    public function media(InboundMessage $message): MediaDownload
    {
        if (blank($this->bot_instance_id)) {
            throw new ZapmizerUnauthorizedException('There is no paired Zapmizer instance for this connection.');
        }

        return $this->instanceClient()->media(
            (int) $this->bot_instance_id,
            $message->id,
            $message->sentAt->getTimestamp(),
        );
    }

    /**
     * `media()` that waits out a `downloading`: sleeps and asks again for
     * each entry of `$waitsSeconds`, and returns the last answer — `attached`,
     * `unavailable` as soon as it shows up, or `downloading` when the list
     * ran out (`isDownloading()` then means "give up or try later").
     *
     * A 429 (`MediaRateLimitedException`) is treated like `downloading`
     * instead of propagating: the wait is Zapmizer's `Retry-After` or the
     * next entry of the list, whichever is longer. A 422
     * (`MediaRejectedException`) still propagates — asking again would not
     * help.
     *
     * It blocks the caller for up to the sum of the waits (67 s by default).
     * That is fine in a command or a simple listener; in a queued job it is
     * better to call `media()` and `$this->release($delay)` on `downloading`
     * (see docs/connect.md, "Receiving media") so the worker is free
     * meanwhile.
     *
     * @param array<int, int|float> $waitsSeconds
     * @param (Closure(int|float): void)|null $sleep Replaces `sleep()` — for tests.
     *
     * @throws ZapmizerConnectException
     */
    public function awaitMedia(InboundMessage $message, array $waitsSeconds = [2, 5, 10, 20, 30], ?Closure $sleep = null): MediaDownload
    {
        $sleep ??= static fn (int|float $seconds) => usleep((int) ($seconds * 1_000_000));

        $answer = $this->mediaOrRateLimit($message);

        foreach ($waitsSeconds as $seconds) {
            if ($answer instanceof MediaDownload && !$answer->isDownloading()) {
                break;
            }

            $sleep($answer instanceof MediaRateLimitedException ? max($seconds, $answer->retryAfter() ?? 0) : $seconds);

            $answer = $this->mediaOrRateLimit($message);
        }

        return $answer instanceof MediaDownload ? $answer : MediaDownload::downloading();
    }

    /**
     * `media()` with the 429 returned instead of thrown, so `awaitMedia()`
     * can wait it out like a `downloading`.
     */
    private function mediaOrRateLimit(InboundMessage $message): MediaDownload|MediaRateLimitedException
    {
        try {
            return $this->media($message);
        } catch (MediaRateLimitedException $exception) {
            return $exception;
        }
    }

    /**
     * Register the package's webhook route on Zapmizer and store the secret.
     * The connect flow no longer needs this — Zapmizer registers the webhook
     * during the hosted pairing and hands id + secret back with the token —
     * but it stays for a connection that lost its webhook (deleted over
     * there, or connected without `webhook_url`). No-op when a secret is
     * already stored: Zapmizer only hands the secret out on creation, so
     * re-registering would orphan the current one.
     *
     * The check is made on a row locked for update, inside a transaction:
     * two concurrent callers would leave the first webhook delivering with
     * a secret nobody stored.
     *
     * @throws ZapmizerConnectException
     */
    public function registerWebhook(?string $url = null): ?WebhookRegistration
    {
        if (!$this->exists) {
            throw ZapmizerConnectException::unexpectedResponse('the connection must be saved before registering its webhook');
        }

        return $this->getConnection()->transaction(function () use ($url) {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            if (filled($locked->webhook_secret)) {
                $this->syncWebhookAttributes($locked);

                return null;
            }

            $registration = $this->instanceClient()->createWebhook($url ?? route('zapmizer.webhook'));

            if (blank($registration->secret)) {
                return $registration;
            }

            $this->storeWebhookSecrets($registration);

            return $registration;
        });
    }

    /**
     * Rotate the webhook secret on Zapmizer and keep both: deliveries in
     * flight signed with the old one still validate. Rotating once does NOT
     * revoke a leaked secret — Zapmizer signs with both until the next
     * rotation. Rotate twice to retire it.
     *
     * @throws ZapmizerConnectException
     */
    public function rotateWebhookSecret(): WebhookRegistration
    {
        if (blank($this->webhook_id)) {
            throw ZapmizerConnectException::unexpectedResponse('there is no registered webhook to rotate');
        }

        $registration = $this->instanceClient()->rotateWebhookSecret((int) $this->webhook_id);

        $this->storeWebhookSecrets($registration);

        return $registration;
    }

    /**
     * Delete the webhook on Zapmizer's side. Without it the webhook keeps
     * delivering to this application — every delivery a 401 in the log —
     * for as long as the team exists over there. Returns false when there
     * is nothing registered.
     *
     * @throws ZapmizerConnectException
     */
    public function deleteRemoteWebhook(): bool
    {
        if (blank($this->webhook_id)) {
            return false;
        }

        $this->instanceClient()->deleteWebhook((int) $this->webhook_id);

        return true;
    }

    /**
     * Forget everything tied to the current Zapmizer team: the paired
     * number, the instance and the webhook. Used when the model re-authorizes
     * a DIFFERENT team — keeping them would send from a number the new token
     * cannot use, and never register a webhook on the new team.
     */
    public function forgetPairing(): static
    {
        return $this->forceFill([
            'phone_number' => null,
            'bot_instance_id' => null,
            'connected_at' => null,
            'webhook_id' => null,
            'webhook_secret' => null,
            'webhook_previous_secret' => null,
            'is_active' => false,
        ]);
    }

    protected function storeWebhookSecrets(WebhookRegistration $registration): void
    {
        $this->forceFill([
            'webhook_id' => $registration->id ?? $this->webhook_id,
            'webhook_secret' => $registration->secret,
            'webhook_previous_secret' => $registration->previousSecret ?? $this->webhook_secret,
        ])->save();
    }

    /**
     * Copy the webhook columns from a freshly loaded row — raw, so the
     * ciphertext is not decrypted and re-encrypted on the way.
     */
    protected function syncWebhookAttributes(self $source): void
    {
        $attributes = ['webhook_id', 'webhook_secret', 'webhook_previous_secret'];
        $raw = $source->getAttributes();

        foreach ($attributes as $attribute) {
            $this->attributes[$attribute] = $raw[$attribute] ?? null;
            $this->syncOriginalAttribute($attribute);
        }
    }
}
