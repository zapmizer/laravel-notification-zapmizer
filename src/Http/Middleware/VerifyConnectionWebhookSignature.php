<?php

namespace NotificationChannels\Zapmizer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class VerifyConnectionWebhookSignature.
 *
 * Guards the per-connection webhook route (`{prefix}/webhook/{key}`,
 * `zapmizer.webhook.per_connection`): the key in the path names the
 * connection, found with one select, and only that connection's secrets
 * (current and previous) may sign the delivery. No application secret, no
 * scan of the table, no unsigned event. Every refusal before a valid HMAC
 * is the same 401 — the response never tells whether the key exists.
 */
class VerifyConnectionWebhookSignature extends VerifyWebhookSignature
{
    protected const KEY_PATTERN = '/^[A-Za-z0-9]{40}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (string) $request->header('X-Zapmizer-Timestamp', '');
        $signature = (string) $request->header('X-Zapmizer-Signature', '');

        if ($timestamp === '' || $signature === '') {
            $this->refuse($request, 'signature headers missing');
        }

        if (!$this->withinTolerance($timestamp)) {
            $this->refuse($request, 'timestamp outside the tolerance window');
        }

        $offered = $this->offeredSignatures($signature);

        if ($offered === []) {
            $this->refuse($request, 'no v1 signature offered');
        }

        $key = $request->route('zapmizerWebhookKey');

        if (!(is_string($key) && preg_match(static::KEY_PATTERN, $key) === 1)) {
            $this->refuse($request, 'malformed connection key');
        }

        $query = $this->connectionModel()::query();
        $connection = $query->where($query->qualifyColumn('webhook_key'), $key)->first();

        if ($connection === null) {
            $this->refuse($request, 'unknown connection key');
        }

        if (!$this->connectionSigned($connection, $timestamp . '.' . $request->getContent(), $offered)) {
            $this->refuse($request, 'no known secret signs this delivery');
        }

        if (!$connection->is_active) {
            Log::warning('zapmizer: delivery refused, connection inactive.', [
                'connection_id' => $connection->getKey(),
                'wid' => $request->header('X-Wid'),
            ]);

            abort(403);
        }

        $request->attributes->set(self::CONNECTION_ATTRIBUTE, $connection);
        $request->route()->forgetParameter('zapmizerWebhookKey');

        return $next($request);
    }
}
