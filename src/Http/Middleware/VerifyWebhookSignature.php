<?php

namespace NotificationChannels\Zapmizer\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Support\PhoneNumber;
use NotificationChannels\Zapmizer\Support\TableExists;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Class VerifyWebhookSignature.
 *
 * Validates the `X-Zapmizer-Signature` of a delivery before anything is
 * processed: HMAC-SHA256 of `"{timestamp}.{raw body}"` with the webhook
 * secret, offered as `v1=<hex>[,v1=<hex of the previous secret>]`. Invalid
 * signature or a timestamp outside the tolerance window → 401, and nothing
 * is read from the body.
 *
 * Three kinds of secret can sign a delivery: the application's own
 * (`zapmizer.webhook.secret`, the webhook registered by hand for the
 * single-tenant setup), the one of each ZapmizerConnection made through
 * the connect flow, and the one of the verify_number webhook
 * (`zapmizer.verify_number.webhook_secret`). Webhooks are registered per
 * Zapmizer team but all land on the same route: the connection is
 * discovered here, by testing the known secrets, and rides along on the
 * request for the controller. A delivery signed with the application
 * secret carries no connection.
 *
 * The `verify_number.*` events have their own scope, both ways. A signed
 * delivery whose body `name` starts with `verify_number.` is checked ONLY
 * against the verify_number secret (no other secret, no connection query),
 * and that secret signs nothing else. The name is peeked from the body only
 * to choose the secret; the HMAC is the proof. Accepted, it sets
 * VERIFY_NUMBER_SIGNED_ATTRIBUTE on the request.
 *
 * The only deliveries accepted WITHOUT a signature are `verify_number.*`,
 * and only while the verify_number secret is not configured: until the team
 * generates it on Zapmizer's side those events arrive unsigned. Once it is
 * configured here, an unsigned `verify_number.*` is a forgery and gets 401.
 * Every other event is signed on Zapmizer's side (every webhook over there
 * has a secret), so an unsigned `message` is a forgery too.
 *
 * Every refusal is logged. A silent 401 is indistinguishable from "nobody
 * sent anything": if the secret rotates on Zapmizer's side, if the
 * connection is deleted here (the webhook over there lives on) or if the
 * APP_KEY changes, EVERY delivery turns into 401 — and without a log nobody
 * finds out until a user complains.
 */
class VerifyWebhookSignature
{
    public const CONNECTION_ATTRIBUTE = 'zapmizer_connection';

    /**
     * Set to true on the request of an accepted verify_number.* delivery
     * that carried a valid signature (never set for an unsigned one).
     */
    public const VERIFY_NUMBER_SIGNED_ATTRIBUTE = 'zapmizer_verify_number_signed';

    /**
     * Event name prefixes of the verify_number webhook: signed with its own
     * secret when one is configured, unsigned otherwise.
     *
     * @var array<int, string>
     */
    protected const UNSIGNED_EVENT_PREFIXES = ['verify_number.'];

    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (string) $request->header('X-Zapmizer-Timestamp', '');
        $signature = (string) $request->header('X-Zapmizer-Signature', '');

        if ($timestamp === '' && $signature === '') {
            if (!$this->acceptsUnsigned($request)) {
                $this->refuse($request, 'signature headers missing');
            }

            return $next($request);
        }

        if ($timestamp === '' || $signature === '') {
            $this->refuse($request, 'signature headers missing');
        }

        if (!$this->withinTolerance($timestamp)) {
            $this->refuse($request, 'timestamp outside the tolerance window');
        }

        // The RAW body: re-serializing the decoded array changes bytes (order,
        // escaping, floats) and the HMAC no longer matches.
        $payload = $timestamp . '.' . $request->getContent();
        $offered = $this->offeredSignatures($signature);

        if ($offered === []) {
            $this->refuse($request, 'no v1 signature offered');
        }

        if ($this->isVerifyNumber($request)) {
            return $this->handleVerifyNumber($request, $next, $payload, $offered);
        }

        // The application's own secret first: no query, no decryption.
        if ($this->signedWith([(string) config('zapmizer.webhook.secret')], $payload, $offered)) {
            return $next($request);
        }

        $connection = $this->matchConnection($request, $payload, $offered);

        if ($connection === null) {
            $this->refuse($request, 'no known secret signs this delivery');
        }

        // An inactive connection is a half-disconnected account: accepting the
        // delivery would feed a channel the screen shows as off.
        if (!$connection->is_active) {
            Log::warning('zapmizer: delivery refused, connection inactive.', [
                'connection_id' => $connection->getKey(),
                'wid' => $request->header('X-Wid'),
            ]);

            abort(403);
        }

        $request->attributes->set(self::CONNECTION_ATTRIBUTE, $connection);

        return $next($request);
    }

    /**
     * A signed verify_number.* delivery: only its own secret is tried — not
     * the application's, not the connections' (no query) — and a missing or
     * wrong secret is refused saying which one, so an operator who rotated
     * it on Zapmizer's side without deploying the env finds out.
     *
     * @param array<int, string> $offered
     */
    protected function handleVerifyNumber(Request $request, Closure $next, string $payload, array $offered): Response
    {
        $secret = $this->verifyNumberSecret();

        if ($secret === null) {
            $this->refuse($request, 'verify_number delivery is signed, but zapmizer.verify_number.webhook_secret (ZAPMIZER_VERIFY_NUMBER_WEBHOOK_SECRET) is not configured');
        }

        if (!$this->signedWith([$secret], $payload, $offered)) {
            $this->refuse($request, 'verify_number signature does not match zapmizer.verify_number.webhook_secret (ZAPMIZER_VERIFY_NUMBER_WEBHOOK_SECRET): was the secret rotated on Zapmizer and not deployed here?');
        }

        $request->attributes->set(self::VERIFY_NUMBER_SIGNED_ATTRIBUTE, true);

        return $next($request);
    }

    /**
     * Unsigned deliveries are accepted for the verify_number.* events while
     * their secret is not configured — the name is peeked from the body for
     * that decision only. With the secret configured, or for anything else
     * (including a body that is not JSON), an unsigned delivery is refused.
     */
    protected function acceptsUnsigned(Request $request): bool
    {
        return $this->isVerifyNumber($request) && $this->verifyNumberSecret() === null;
    }

    /**
     * Whether the body `name` is a verify_number.* event. Peeked to choose
     * the secret (or to tell whether unsigned is acceptable); never trusted
     * for anything else.
     */
    protected function isVerifyNumber(Request $request): bool
    {
        $payload = json_decode($request->getContent(), true);

        return static::isVerifyNumberName(is_array($payload) ? $payload['name'] ?? null : null);
    }

    /**
     * Whether an event name reaches the verify_number handlers. Besides the
     * exact prefix, any name the WebhookController would dispatch to a
     * `handleVerifyNumber*` method counts (`Verify_Number.verified`,
     * `verify-number.verified`, ` verify_number.verified`...): otherwise a
     * near-miss name signed with another secret would skip the verify_number
     * scope and still mark a number verified.
     *
     * @param mixed $name
     */
    public static function isVerifyNumberName($name): bool
    {
        if (!is_string($name)) {
            return false;
        }

        return Str::startsWith($name, static::UNSIGNED_EVENT_PREFIXES)
            || Str::startsWith(Str::studly(str_replace('.', '_', $name)), 'VerifyNumber');
    }

    protected function verifyNumberSecret(): ?string
    {
        $secret = config('zapmizer.verify_number.webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * The connections whose paired number matches the `X-Wid` are queried
     * first; only on a miss is the whole table scanned. Only ordering: the
     * proof is still the HMAC. On the happy path a single row is decrypted
     * — and a rotten credential of another tenant is not even touched.
     *
     * @param array<int, string> $offered
     */
    protected function matchConnection(Request $request, string $payload, array $offered): ?ZapmizerConnection
    {
        if (!$this->connectionsTableExists()) {
            return null;
        }

        $tried = [];
        $wid = PhoneNumber::fromWid($request->header('X-Wid'));

        if ($wid !== '') {
            $candidates = $this->connectionsQuery()->whereIn('phone_number', PhoneNumber::variants($wid))->get();

            foreach ($candidates as $connection) {
                $tried[] = $connection->getKey();

                if ($this->connectionSigned($connection, $payload, $offered)) {
                    return $connection;
                }
            }
        }

        $rest = $this->connectionsQuery()->whereKeyNot($tried)->cursor();

        foreach ($rest as $connection) {
            if ($this->connectionSigned($connection, $payload, $offered)) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $offered
     */
    protected function connectionSigned(ZapmizerConnection $connection, string $payload, array $offered): bool
    {
        // A credential that does not decrypt (encrypted with an old APP_KEY,
        // corrupted row) takes down only its own connection. Without this
        // try, the exception would surface as a 500 and the channel of EVERY
        // tenant would go down with it.
        try {
            $secrets = $connection->signingSecrets();
        } catch (Throwable $exception) {
            Log::error('zapmizer: unreadable webhook secret, connection skipped while matching.', [
                'connection_id' => $connection->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        return $this->signedWith($secrets, $payload, $offered);
    }

    /**
     * @param array<int, string> $secrets
     * @param array<int, string> $offered
     */
    protected function signedWith(array $secrets, string $payload, array $offered): bool
    {
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            $expected = hash_hmac('sha256', $payload, $secret);

            foreach ($offered as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A single-tenant application that never published the connections
     * migration must not answer 500 to a signed delivery.
     */
    protected function connectionsTableExists(): bool
    {
        return TableExists::for(new ($this->connectionModel()));
    }

    /**
     * @return Builder<ZapmizerConnection>
     */
    protected function connectionsQuery(): Builder
    {
        return $this->connectionModel()::query()->withWebhookSecret();
    }

    /**
     * `v1=<hex>[,v1=<hex of the previous secret>]`.
     *
     * @return array<int, string>
     */
    protected function offeredSignatures(string $header): array
    {
        return collect(explode(',', $header))
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => Str::startsWith($part, 'v1='))
            ->map(fn (string $part) => Str::after($part, 'v1='))
            ->filter()
            ->values()
            ->all();
    }

    protected function withinTolerance(string $timestamp): bool
    {
        if (!is_numeric($timestamp)) {
            return false;
        }

        $tolerance = (int) config('zapmizer.webhook.tolerance', 300);

        return abs(now()->getTimestamp() - (int) $timestamp) <= $tolerance;
    }

    /**
     * Enough context to diagnose without leaking a secret: the target wid
     * says which number the delivery was for, the reason separates "nothing
     * was sent properly" from "the secret no longer matches". The offered
     * signature is NOT logged — it derives from the secret.
     */
    protected function refuse(Request $request, string $reason): never
    {
        Log::warning('zapmizer: delivery refused by signature.', [
            'reason' => $reason,
            'wid' => $request->header('X-Wid'),
            'timestamp' => $request->header('X-Zapmizer-Timestamp'),
            'ip' => $request->ip(),
        ]);

        abort(401);
    }

    /**
     * @return class-string<ZapmizerConnection>
     */
    protected function connectionModel(): string
    {
        return config('zapmizer.models.connection', ZapmizerConnection::class);
    }
}
