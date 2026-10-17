# Connecting a WhatsApp number per model (multi-tenant)

This guide wires the Zapmizer **connect flow** into a Laravel application: each model of yours (a `Team`, a `User`, ...) authorizes its own Zapmizer account **and pairs a WhatsApp number** in a single hosted popup, and from then on sends from — and receives on — that number. It mirrors Laravel Cashier: a `Connectable` trait on the model, a package-owned table, a controller with stable response codes, and events your app listens to.

The single-tenant setup (`ZAPMIZER_API_TOKEN` + `ZAPMIZER_FROM_NUMBER`) keeps working — `Connectable` is an addition, not a replacement.

## How it works

```
your app ──(X-Partner-Key)──> POST /api/connect/sessions { redirect_uri, state, webhook_url, expires_in, external_id } ──> { url }   [popup]
user authorizes a team AND pairs a number (QR code) on the hosted page
        ──> redirect to your callback with ?code&state   (or ?error=access_denied|plan_limit|subscription_required)
your app ──(X-Partner-Key)──> POST /api/connect/token { code }
        ──> { token, user_id, team_id, team_name, phone_number, bot_instance_id, webhook_id, webhook_secret }
everything stored (token and secret encrypted), connection ACTIVE
Zapmizer ──(signed)──> POST /zapmizer/webhook ──> MessageReceived event
```

There is no wizard on your side: no instance creation, no QR code rendering, no polling. The popup opens, the user comes back, the connection is ready. Your screen needs one button and one panel.

All connections share `POST /zapmizer/webhook` by default. As an opt-in alternative, each connection can get its own URL, `POST /zapmizer/webhook/{key}`: see [Per-connection webhook URL](#per-connection-webhook-url-opt-in).

## 1. Zapmizer-side setup

You need **partner credentials** (id + secret), issued by Zapmizer for your application. They authenticate the hosted connect flow; each connected team's own token is obtained through it and stored by the package.

**This flow needs a Zapmizer with the hosted pairing** — `POST /api/connect/sessions` accepting `webhook_url` and the token exchange answering `phone_number`/`bot_instance_id`/`webhook_id`/`webhook_secret`. This version of the package needs Zapmizer **1.148.10 or later**: the token exchange with `user_id` and `team_id`, the reconnect, the token revocation and the partner `users` endpoints arrived in 1.148.8, and the inbox `appearance` and the embed's `user.email` in 1.148.10. Without `user_id` and `team_id`, which the contract requires, the callback reports `exchange_failed` and stores nothing.

Zapmizer validates `webhook_url` the way it validates `redirect_uri`: **https in production**, host on your partner allowlist, public address (anti-SSRF). The package sends `route('zapmizer.webhook')` — make sure `APP_URL` resolves to the public https host, and that this host is on your partner allowlist over there. With the [per-connection webhook URL](#per-connection-webhook-url-opt-in) the host stays the same; only the path changes (`/zapmizer/webhook/{key}`).

## 2. Package setup

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=config
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=zapmizer-migrations-connect
php artisan migrate
```

`zapmizer-migrations-connect` publishes only the migration this flow needs; `--tag=migrations` publishes both this one and the verify-number one (`zapmizer-migrations-verify`). A `verify_number.*` delivery to an app without the `whatsapp_verifieds` table is acknowledged and ignored.

This creates `zapmizer_connections` (one row per connected model, polymorphic). The stub uses `morphs('connectable')`, which makes `connectable_id` a **bigint** — if the models you connect use UUID/ULID keys, edit the published migration to `uuidMorphs('connectable')` / `ulidMorphs('connectable')` before running it. `zapmizer_team_id` is unique: one connectable per Zapmizer team (see `team_already_connected` below).

```env
ZAPMIZER_BASE_URI=https://app.zapmizer.com/api/
ZAPMIZER_API_VERSION=2025-06-27          # contract version sent as `api-version` (when set)
ZAPMIZER_PARTNER_ID=...
ZAPMIZER_PARTNER_SECRET=...
ZAPMIZER_DEFAULT_COUNTRY_CODE=55         # completes numbers typed in national format
```

## 3. The Connectable model

The `Connectable` trait plus the `Contracts\Connectable` interface — the pair mirrors `MustVerifyWhatsapp`:

```php
use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;
use NotificationChannels\Zapmizer\Contracts\Connectable;

class Team extends Model implements Connectable
{
    use ConnectsZapmizer;
}
```

| Method | What it does |
|---|---|
| `zapmizerConnection()` | `MorphOne` to the `ZapmizerConnection` record. |
| `hasActiveZapmizer()` | Active and with a paired number. |
| `zapmizer()` | A `Zapmizer` client authenticated as the connection (token + `api-version`). |
| `zapmizerMessage($to)` | A `ZapmizerMessage` with `from` = the paired number — the analogue of `$user->charge()`. |
| `zapmizerExternalId()` | The model's customer id on Zapmizer, sent by the connect route as `external_id`; it keys the subscription and the checkout (section 8). Default: `(string) $this->getKey()`. |

```php
$team->zapmizerMessage('5511999999999')->text('Hello')->send();
```

Both `zapmizer()` and `zapmizerMessage()` throw `ZapmizerConnectException` when the model isn't connected.

Override `zapmizerExternalId()` when the key alone does not name the customer. Two kinds of model connected in the same app collide: a `Team` 5 and a `User` 5 both send `5` and become the **same customer** on Zapmizer. Prefix the id:

```php
class Team extends Model implements Connectable
{
    use ConnectsZapmizer;

    public function zapmizerExternalId(): string
    {
        return 'team-' . $this->getKey();
    }
}
```

Zapmizer matches `external_id` without regard to case (`Abc` and `abc` are the same customer), so the ids must be unique ignoring case. An id outside `[A-Za-z0-9_.-]`, longer than 191 characters, empty, `.` or `..` is a programming error: `start` throws `InvalidArgumentException` (a 500) before calling Zapmizer.

The `ZapmizerConnection` model (`zapmizer.models.connection` to subclass it) exposes: `api_token`, `zapmizer_team_id`, `zapmizer_team_name`, `phone_number`, `bot_instance_id`, `connected_at`, `webhook_id`, `webhook_secret`, `webhook_previous_secret`, `is_active`. All of them are filled by the callback in one go. The token and both secrets are `encrypted` casts and hidden from serialization; `api_token_masked` (`••••1234`, or `••••` when the stored token no longer decrypts) and `has_webhook_secret` (whether a webhook secret is stored; `false` means the number sends but cannot verify what it receives) are what a screen gets. A subclass of the model that redefines `$appends` must list both. `hasApiToken()` / `isConnected()` check the raw attribute and never decrypt, so a token written with an old `APP_KEY` shows as connected and fails loudly at send time — not on every `toArray()`. Do **not** add an accessor over those attributes — it would win over the cast and return the ciphertext.

## 4. Who gets connected: the resolver

The controller asks a resolver which model the current request connects. The default returns `$request->user()` (which must implement `Contracts\Connectable`, or it throws a `ZapmizerConnectException` naming the class). For team-scoped connections, point `zapmizer.connect.resolver` at your own — the return type is the contract. When there is nothing to connect (a user without a team), throw `ZapmizerConnectException::noConnectable()`: the JSON endpoints answer 403 `{"code": "no_connectable"}` and the popup callback reports `no_connectable` on its result page. Returning `null` is not an option — it would surface as a `TypeError` (500).

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

class ResolvesCurrentTeam implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        return $request->user()->currentTeam
            ?? throw ZapmizerConnectException::noConnectable('The user has no current team.');
    }
}
```

```php
// config/zapmizer.php
'connect' => ['resolver' => App\Zapmizer\ResolvesCurrentTeam::class],
```

The state stored at `start` is bound to the resolved model, so a callback for another model is refused (`invalid_state`).

## 5. The connect flow

### Routes

All under the package prefix (`zapmizer`), behind `web` + `auth`:

| Route name | Method | Returns |
|---|---|---|
| `zapmizer.connect.show` | GET | `{ connection }` — the model's connection, or `null`. With `?live=1` the paired instance is queried on Zapmizer and `connection` also carries `state` and `is_online` (below). |
| `zapmizer.connect.start` | POST | `{ url, expires_at }` — open `url` in a popup (520×720 works). `expires_at` is ISO 8601 with the offset (`2026-09-07T01:00:00+00:00`), or `null`. Stores the `state` in the session and sends, with the session, `route('zapmizer.webhook')` as `webhook_url` (with `zapmizer.webhook.per_connection` on, the connection's own `route('zapmizer.webhook.connection', ['zapmizerWebhookKey' => $key])`, [below](#per-connection-webhook-url-opt-in)), the model's `zapmizerExternalId()` as `external_id` and the state's TTL as `expires_in` (`zapmizer.connect.state_ttl_minutes` × 60, at least Zapmizer's 900 seconds), so the hosted link lives as long as the state. 10 a minute. |
| `zapmizer.connect.callback` | GET | HTML page that `postMessage`s `{ source: 'zapmizer-connect', status, message }` to the opener and closes — it never answers a 500 (that would leave the opener waiting). Stores token, team, number, instance and webhook; the connection is born **active**. It never calls `/webhooks`. |
| `zapmizer.connect.destroy` | DELETE | Revokes the connection's token on Zapmizer (`DELETE /api/connect/token`, best-effort: a failure is a warning and the local row goes anyway) and deletes the local connection with its credentials. Zapmizer drops the webhook the connect created only when no other live grant uses the same URL: grants of earlier connects that were never revoked (`revoke_previous_token` off, section 9) keep it. The remedy then is a [per-connection webhook URL](#per-connection-webhook-url-opt-in). |
| `zapmizer.connect.reconnect` | POST | Brings the stored number back with the token the connection already holds — see "Reconnecting a number from the panel". 10 a minute. |
| `zapmizer.connect.reconnect.callback` | GET | HTML page that `postMessage`s `{ source: 'zapmizer-reconnect', status, message }` to the opener and closes. Stores nothing. |

### The callback's `status`

Every outcome the popup reports has a stable `status` — the message that goes with it is a fallback, not a contract:

| `status` | Meaning |
|---|---|
| `ok` | Connected: token, number and webhook stored, connection active. Reload `show`. `NumberConnected` fires. |
| `denied` | The user cancelled on the hosted page (`?error=access_denied`). |
| `plan_limit` | The Zapmizer plan has no room for another number. The user frees one up (or upgrades) over there and tries again. |
| `subscription_required` | The Zapmizer account was linked, but it has no active subscription. Connecting again leads to the checkout on the hosted page. |
| `invalid_state` | The session `state` is missing, mismatched, bound to another model or expired (`zapmizer.connect.state_ttl_minutes`, 30 by default). |
| `exchange_failed` | The code is unknown/used, the partner key was refused, Zapmizer is down or answered something that is not JSON, the token came without a number or with an invalid id, or the hosted page sent an `?error=` the package does not know (logged as `zapmizer: unknown connect error.`) — details in the log. Nothing was stored. |
| `rate_limited` | Zapmizer kept answering 429 on the code exchange (below). Close the window and connect again in a minute. |
| `webhook_failed` | Number paired and stored, connection **active**: it sends, but no webhook secret is stored, so it cannot verify what it receives (below). `has_webhook_secret` is `false`. Disconnect and connect again to receive. `NumberConnected` fires. |
| `team_already_connected` | Another model here already holds this Zapmizer team. One connectable per team — two would receive every message twice. The new token is revoked. |
| `no_connectable` | The resolver found nothing to connect on this request. |

**The `state` comes first.** It is checked before anything the popup brings back, `?error=` included: a return whose `state` is missing, expired or bound to another model answers `invalid_state`, and nothing that depends on the return runs. A `state` that does not match the pending one (another tab's, or a forged one) leaves the pending state in the session; a matching one spends it, whatever comes next.

**A 429 on the code exchange** does not spend the code. Instead of answering the opener, the popup shows a page that refreshes after `Retry-After` + 1 second (10 seconds without a usable header) and tries the same code again. It gives up with `rate_limited` after 5 attempts, when the wait would pass 240 seconds from the first attempt, or when the state would expire first. A refresh that comes back with another code answers `invalid_state` without calling Zapmizer.

**Orphan tokens are revoked.** A token the exchange handed out but the package did not store — another model holds the team (`team_already_connected`), or no number came with it (`exchange_failed`) — would stay valid with no one holding it. It is revoked right away, in one attempt; a failure is the warning `zapmizer: the orphan token was not revoked.` and never changes the status.

Re-authorizing the **same** Zapmizer team overwrites number and instance with what the new pairing brought, and keeps the stored webhook secret when Zapmizer reused the same webhook. A **different** team resets everything here: the new team's number, instance and webhook take over. Nothing is deleted on Zapmizer from the callback; the old webhook goes when the previous token is revoked (`revoke_previous_token`, section 9).

### The webhook secret

Zapmizer registers the webhook for the session's `webhook_url` on the team while pairing, and hands the id and secret back with the token. The secret is only ever given **once**, on creation — so when the team **already had** a webhook for that URL (a reconnection, a manual registration, another live connection), Zapmizer reuses it and answers `webhook_secret: null`.

The callback **never calls `/webhooks`**: Zapmizer refuses those endpoints to a connect token (403 `missing_ability`). So:

- a `null` secret for the webhook stored on the connection (same `webhook_id`) keeps the secret already stored;
- a `null` secret without a stored, readable one — another `webhook_id`, a first connect, or a stored secret that no longer decrypts because `APP_KEY` changed — stores the connection **active** and without a secret, logs `zapmizer: connected without a webhook secret.` and reports `webhook_failed`. The number sends, but no delivery to it can be verified.

The remedy is to disconnect (`destroy` revokes the token) and connect again: with no other live grant on that URL, Zapmizer creates the webhook again and hands a new secret. If it still comes back `null`, another grant keeps the webhook alive; the way out is a [per-connection webhook URL](#per-connection-webhook-url-opt-in). In that mode, a connection without a secret gets a new key on its next connect: a new URL, so Zapmizer creates a new webhook and the secret comes back.

A stored token or secret that no longer decrypts (`APP_KEY` changed) counts as absent, with a warning, and the next connect overwrites it — on the same team or on a team switch.

### `show?live=1`

The panel that shows the connected number usually wants to say whether it is online. `GET zapmizer/connect?live=1` queries `GET /api/bot-instances/{id}/connection` with the connection's token and folds the answer into `connection`:

| `connection.state` | `is_online` | Meaning |
|---|---|---|
| `connected` | `true`/`false` | Zapmizer's own state; `is_online` is its `is_online`. The other states (`qr_pending`, `connecting`, `disconnected`, `off`) come through as they are. |
| `reauth_required` | `false` | The stored token was revoked on Zapmizer, or no longer decrypts (`APP_KEY` changed; logged as a warning) — the user has to connect again. |
| `instance_gone` | `false` | The instance was deleted on Zapmizer (404 on both routes, below). |
| `zapmizer_unavailable` | `false` | Zapmizer is down, refused the call with another 4xx (403, 422, 429, ...), or answered something that is not JSON. |
| `null` | `false` | Nothing to query: no token or no instance stored. |

Without `live` no request leaves your server and `state`/`is_online` are absent. The route is throttled (60/min) because `live` reaches Zapmizer. The stubs poll it every 15 seconds, and every 5 seconds for two minutes after a reconnect (below).

**Official numbers.** Zapmizer answers 404 on `/connection` to a team without its QR feature, which is the case of an official number (Meta's API). On that 404 the route asks `GET /api/bot-instances/{id}` instead, as Zapmizer recommends, and `state` comes from its `is_online`: `connected` or `disconnected`. That path has no `off`, `qr_pending` or `connecting`. Only a 404 there too is `instance_gone`; a 401 is `reauth_required` and anything else `zapmizer_unavailable`.

**A number changed on WhatsApp is stored.** When the answer is `connected` with a number other than the stored `phone_number`, the route stores the new one before answering, so `connection.phone_number` already shows it. Without it, the webhook would keep refusing the new number's events (it matches `X-Wid` and the body's `wid` against `phone_number`) until a new connect. It writes:

- `phone_number`: the new number;
- `is_active`: `true`, as the connect does for a paired number;
- `connected_at`: now. It dates the stored number, so it is the moment the panel saw the change, not the moment WhatsApp made it.

The same number, a `null` number and any other state write nothing: the panel polls this route, and the number of a state other than `connected` may be a stale one. A failed write is reported, and the answer keeps the stored number. To change the rule (for example, to keep a connection your app deactivated inactive), override `syncConnectedNumber(ZapmizerConnection $connection, InstanceConnection $instance)` in a subclass of the controller.

### Reconnecting a number from the panel

When the paired number drops (the phone was logged out, the instance is `disconnected` or `off`), `POST zapmizer/connect/reconnect` (`zapmizer.connect.reconnect`) asks Zapmizer to bring it back with the token the connection already holds. No new token, nothing stored. It answers 200:

| JSON | Meaning |
|---|---|
| `{ status: 'online' }` | Back online. |
| `{ status: 'starting' }` | Booting; reload `show?live=1` in a moment. |
| `{ status: 'needs_client', url, expires_at }` | The customer has to act on Zapmizer: open `url` in a popup (the stubs name it `zapmizer-reconnect`). `expires_at` is ISO 8601 with the offset, or `null`. |
| `{ status: 'refused', code, message }` | Zapmizer refused with a 402: `code` is `plan_limit`, `subscription_required` or a code Zapmizer adds later; `message` is Zapmizer's text, or the package's when it sent none. `ReconnectRefused` fires. |

Or one of these codes:

| `code` | HTTP | Meaning |
|---|---|---|
| `no_instance` | 409 | Nothing to reconnect: no connection, no token or no instance stored, or Zapmizer answered 404. Connect again. |
| `reauth_required` | 409 | The token is no longer valid (401, 403 — logged as `zapmizer: reconnect refused.`), or it no longer decrypts. Connect again. |
| `zapmizer_unavailable` | 503 | Zapmizer is down, rate-limited the call (429), refused it otherwise (422, another 409, a 402 without a code), answered something unexpected, or `base_uri` is invalid. Reported. |
| `no_connectable` | 403 | The resolver found nothing to connect. |

Past 10 calls a minute the route's own throttle answers 429. Only `needs_client` keeps the state in the session, under its own key (`zapmizer_reconnect`): a reconnect never touches a pending connect. The popup comes back to `zapmizer.connect.reconnect.callback`, which checks the state first, like the connect, and posts `{ source: 'zapmizer-reconnect', status, message }` to the opener:

| `status` | Meaning |
|---|---|
| `reconnected` | The number is back. Poll `show?live=1` (the stubs' `beginTurnOn()`). |
| `denied` | The customer cancelled (`?error=access_denied`). |
| `plan_limit` | The plan has no room for the number online. |
| `subscription_required` | The account has no active subscription. |
| `invalid_state` | As in the connect. |
| `failed` | Anything else; an unknown `?error=` is logged as `zapmizer: unknown reconnect error.`. |
| `no_connectable` | The resolver found nothing to connect. |

An `?error=` wins over `status=reconnected`. The page is the Blade view `zapmizer::reconnect-result`, apart from `connect-result`: a `connect-result` you published keeps serving the connect and does not reach the reconnect.

### Events

Two Laravel events let your app react without extending the controllers:

| Event | Payload | Fires when |
|---|---|---|
| `NumberConnected` | `$connectable`, `$connection` | the connect callback stored the paired number (`ok` or `webhook_failed`) |
| `ReconnectRefused` | `$connectable`, `$connection`, `$code`, `$message` | Zapmizer refused a reconnect with a 402; `$message` is Zapmizer's text, or `null` |

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Events\NumberConnected;
use NotificationChannels\Zapmizer\Events\ReconnectRefused;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;

Event::listen(NumberConnected::class, function (NumberConnected $event) {
    Log::info('WhatsApp connected.', [
        'team' => $event->connectable->getKey(),
        'number' => $event->connection->phone_number,
        'ip' => request()->ip(),
    ]);
});

Event::listen(ReconnectRefused::class, function (ReconnectRefused $event) {
    if ($event->code === ErrorCode::SUBSCRIPTION_REQUIRED) {
        Log::info('Reconnect refused: no subscription.', ['team' => $event->connectable->getKey()]);
    }
});
```

The payload carries no token. A listener runs inside the request, so the popup waits for it: queue heavy work (`ShouldQueue`). One that throws is reported and does not change the answer — the popup still says `ok`, the reconnect still answers `refused`. The events carry no request; use `request()` when the listener needs it.

### JSON error codes

The JSON endpoints (`show`, `start`, `destroy`, `reconnect`) answer these themselves, with or without `Accept: application/json` (the package's exceptions do not render):

| `code` | HTTP | Meaning |
|---|---|---|
| `zapmizer_unavailable` | 503 | Zapmizer is down, misbehaving or refused the session (404, 409, 422, 429), the configuration is missing or invalid (partner credentials, `base_uri`, an authenticated user that is not `Connectable` with the default resolver), or the answer was unexpected — back off and retry. Reported: the report says which. |
| `partner_unauthorized` | 503 | Your partner credentials were refused — configuration error, logged and reported. |
| `no_connectable` | 403 | The resolver found nothing to connect on this request (no user, a user without a team). |
| `no_instance` | 409 | `reconnect` only: nothing to reconnect (above). |
| `reauth_required` | 409 | `reconnect` only: the token is no longer valid or no longer decrypts (above). |

### Inertia + Vue components

A connect button, a connection panel and the composable that loads the state ship as publishable stubs, Jetstream-style:

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=zapmizer-wizard
```

This copies into `resources/js/`:

- `components/zapmizer/ConnectButton.vue` — opens the popup, listens to its `postMessage` (checking `event.origin` and `source === 'zapmizer-connect'`), shows a message per `status`, and has a "Já conectei" button for a popup that was closed by hand (emits `recheck`). It emits `connected` on `ok` and on `webhook_failed`, which also shows the warning that the number cannot receive.
- `components/zapmizer/ConnectionPanel.vue` — the connected number, its live state (from `show?live=1`), a "Religar número" button (when the connection is active and `state` is `disconnected`, `off` or `qr_pending`; it calls `relinkZapmizer()` and listens to the `zapmizer-reconnect` popup), the warning "this number sends but does not receive" when `webhook_id` is set and `has_webhook_secret === false`, "Trocar número" (a new connect, emits `reconnect`) and disconnect.
- `composables/useZapmizerIntegration.ts` — `reload({ live })`, `start()`, `relink()`, `disconnect()`, `startPolling()`, `stopPolling()` and `beginTurnOn()`; `openZapmizerConnect()` for the button and `relinkZapmizer()` for the panel.

The polling asks `show?live=1` right away and then every 15 seconds. `beginTurnOn()` is for the moment after a reconnect. It shows the number as `connecting` and polls every 5 seconds for two minutes, until the state is `connected`. For the first 30 seconds it also ignores an `off`, which Zapmizer still answers while the number boots. A late answer never overwrites a newer one. The polling stops when the component unmounts, when the connection is gone, on `reauth_required` and `instance_gone`, and on a 401, 403 or 419 from `show`. A `reload()` in the middle of a poll does not stop it. Network errors, 429 and 503 keep it going. The panel emits `relinked` when its "Religar número" works (`online`, `starting`, or the popup's `reconnected`):

```vue
<script setup lang="ts">
import { onMounted } from 'vue';
import ConnectionPanel from '@/components/zapmizer/ConnectionPanel.vue';
import { useZapmizerIntegration } from '@/composables/useZapmizerIntegration';

const { integration, reload, startPolling, beginTurnOn, disconnect } = useZapmizerIntegration();

onMounted(startPolling);
</script>

<template>
  <ConnectionPanel
    v-if="integration"
    :integration="integration"
    @refresh="reload({ live: true })"
    @relinked="beginTurnOn()"
    @disconnect="disconnect()"
  />
</template>
```
- `types/zapmizer.ts` — `ZapmizerConnection`, `ZapmizerConnectStatus`, `ZapmizerConnectMessage`, `ZapmizerReconnectStatus`, `ZapmizerReconnectMessage`, `ZapmizerRelinkResult`.

The lasting warning of a `webhook_failed` is the panel's: the button emits `connected`, the screen switches to the panel, and the button's warning goes with it.

They expect `axios`, `lucide-vue-next` and Ziggy's `route()`; the markup uses utility classes from the app they came from (`gp-*`) — restyle them as yours. The popup pages are Blade views you can publish with `--tag=views` (`resources/views/vendor/zapmizer/connect-result.blade.php`, `connect-wait.blade.php` and `reconnect-result.blade.php`).

## 6. Receiving messages: the signed webhook

Every webhook on Zapmizer has a secret, and every bot event (`message`, `qr`, ...) is signed with it:

```
X-Wid: 5581911110000@c.us                     # the bot that received the message
X-Zapmizer-Timestamp: 1757203200              # seconds
X-Zapmizer-Signature: v1=<hex>[,v1=<hex>]     # HMAC-SHA256(secret, "{timestamp}.{raw body}")
                                              # both current and previous secret during a rotation
```

The package applies `VerifyWebhookSignature` to `POST /zapmizer/webhook` itself. Two kinds of secret can sign a delivery:

- **`zapmizer.webhook.secret`** (`ZAPMIZER_WEBHOOK_SECRET`) — the secret of the webhook you registered by hand on Zapmizer's "Webhooks" screen, the single-tenant setup. It is tested first, without touching the database. A delivery it signs carries **no connection** (`$event->connection === null`).
- **each `ZapmizerConnection`'s secret** — registered by the connect flow. The connections whose `phone_number` matches `X-Wid` are queried first; only on a miss is the whole table scanned (a signed delivery is never refused because `X-Wid` lied — the proof is the HMAC). An application that never published the `zapmizer_connections` migration skips this leg: the table's existence is checked (and cached once true), not assumed. A refused delivery always goes through the whole scan; the [per-connection webhook URL](#per-connection-webhook-url-opt-in) finds the owner by its path instead.

It accepts a 5-minute clock drift (`zapmizer.webhook.tolerance`), refuses with **401 and a log line** (reason, wid, timestamp, ip — never the signature), and refuses an inactive connection with 403. A credential that no longer decrypts (`APP_KEY` changed) only takes its own connection out of the matching.

**Unsigned deliveries** are accepted for **`verify_number.*` only** — Zapmizer sends those outside the bot, without a signature, by construction. A `message` (or any other event) without the signature headers is refused with 401: it was not sent by Zapmizer. There is no flag to turn this off. If you rely on `verify_number.*`, note that anyone can POST one — harden `zapmizer.routes.webhook_middleware` with an IP allowlist or a throttle (see [verify-number.md](verify-number.md#6-the-webhook)).

### The `MessageReceived` event

The `message` event is translated to an `InboundMessage` and dispatched — nothing is persisted:

```php
use NotificationChannels\Zapmizer\Events\MessageReceived;

Event::listen(function (MessageReceived $event) {
    $m = $event->message;    // InboundMessage — always present, first argument
    $event->connection;      // ZapmizerConnection that received it — NULL when the
                             // delivery was signed with the single-tenant secret
    $event->payload;         // the raw webhook payload

    $m->id;                  // whatsapp-web.js serialized id — dedupe on it (see below)
    $m->from;                // the chat: the person's wid in a DM, the group's wid in a group
    $m->fromPhone;           // digits of `from` ('' for a broadcast, meaningless for a group)
    $m->author;              // who wrote, in a group (null in a DM)
    $m->authorPhone;         // digits of `author` ('' when null or unresolved)
    $m->to;                  // receiving bot wid (X-Wid)
    $m->body; $m->type;      // 'chat', 'image', 'document', ...
    $m->hasMedia; $m->mediaMetadata;   // mimetype, filename, caption, size, ... (bytes are not delivered — see "Receiving media")
    $m->mediaFilename(); $m->mediaMimeType();  // the original name and type, from the metadata
    $m->isGroup; $m->isBroadcast;      // group message / status@broadcast (a status update, arrives as a DM)
    $m->hasUnresolvedSender; // sender (or group author) arrived as a @lid wid — its digits are NOT a phone number
    $m->fromMe;              // mirrored from the payload — never true: whatsapp-web.js does not emit `message` for the bot's own sends
    $m->sentAt;

    if ($m->isGroup || $m->isBroadcast || $m->hasUnresolvedSender) {
        return;
    }

    // Single-tenant deliveries have no connection: reply through the
    // configured channel instead.
    $connection = $event->connection;

    if ($connection === null) {
        return;
    }

    $connection->connectable;  // your Team
    $connection->message($m->fromPhone)->text('Got it!')->send();
});
```

**Deliveries are retried.** Zapmizer retries a webhook up to 3 times when your endpoint fails or is slow, and a retry within the tolerance window is a valid, signed delivery — the package does not deduplicate. A listener with side effects (storing, replying, charging) must be idempotent on `$message->id`.

`WebhookReceived` and `WebhookHandled` also carry the connection. Other events (`qr`, `disconnected`, ...) fall through to `handle{StudlyName}` methods — extend `WebhookController` to add yours; `$this->connection()` gives them the connection.

Use `NotificationChannels\Zapmizer\Support\PhoneNumber` to match `fromPhone` against what your users typed: `normalize()` (E.164 without `+`, completes `zapmizer.default_country_code` — `55` by default — on 10/11-digit national numbers, drops the carrier zero), `variants()` (with/without the Brazilian ninth digit, mobiles only, `55` numbers only), `digits()`, `fromWid()`.

### Receiving media

The webhook carries **no bytes**, and no URL to them either: the bot downloads the media in the background and the application fetches it from Zapmizer's API (`GET /api/whatsapp-messages/media`, with the connection's token). **The `message` webhook fires before that download is done** — asking right away usually gets a "still downloading", and the consumer has to come back. Zapmizer keeps trying for **600 seconds counted from the message's `timestamp`** (a required parameter of the endpoint, and it must not be in the future); after that (or for a type it does not download, or a view-once message) the media is declared unavailable for good.

```php
$download = $connection->media($m);    // one request, no waiting

$download->state;         // 'attached' | 'downloading' | 'unavailable'
$download->isAttached();  // ->isDownloading() / ->isUnavailable()
$download->mimeType;      // Content-Type          (attached only)
$download->filename;      // from Content-Disposition — Zapmizer's `<id>.<ext>`, not the sender's name: use $m->mediaFilename() for that
$download->size;          // bytes written to the file

$download->path();        // temporary file with the bytes — deleted when $download is destroyed
$download->stream();      // fresh read handle: Storage::disk('s3')->put($key, $download->stream())
$download->contents();    // the whole file as a string — only if you need it in memory
```

`media()` copies the bytes into a temporary file (on the way they sit in `php://temp`: memory up to 2 MB, then disk); nothing is held in memory as a string unless you call `contents()`. Move or copy the file (`Storage::put`, `putFileAs(new File($download->path()))`) before the object goes away. It needs the connection's `bot_instance_id` (a connection that lost its pairing throws `ZapmizerConnectException::notPaired()`; `instanceClient()` without a token throws `ZapmizerUnauthorizedException`) and passes `$m->id` and `$m->sentAt` along — the timestamp is required over there: the 600 s window is counted from it, and it is what lets Zapmizer answer `unavailable` instead of `downloading` forever.

Two answers are exceptions rather than states, because asking again would not help — each has its own class under the `ZapmizerApiException` base, so a `catch` can tell them apart:

```php
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;

try {
    $download = $connection->media($m);
} catch (MediaRejectedException $e) {      // 422: a timestamp in the future, or an instance on Meta Cloud (no media there)
    Log::warning($e->reason());            //      Zapmizer's own explanation — retrying will not help
} catch (MediaRateLimitedException $e) {   // 429: 60 requests a minute per user and instance
    $this->release($e->retryAfter() ?? 60); //     seconds from Zapmizer's Retry-After, null when it sent none
}
```

`awaitMedia()` with its default schedule makes at most 6 requests in 67 s, well under the limit — a fleet of jobs polling the same instance is what could reach it, so on a `downloading` release the job with a delay instead of re-dispatching it right away.

**In a queued job, re-dispatch instead of sleeping.** The listener queues a job with the message; the job calls `media()` and releases itself on `downloading`:

```php
class StoreInboundMedia implements ShouldQueue
{
    public int $tries = 8;

    public function __construct(public ZapmizerConnection $connection, public InboundMessage $message) {}

    public function handle(): void
    {
        $download = $this->connection->media($this->message);

        if ($download->isDownloading()) {
            $this->release(delay: min(60, 5 * $this->attempts()));   // come back later, worker stays free

            return;
        }

        if ($download->isUnavailable()) {
            return;   // log it, tell the user — it will not arrive
        }

        Storage::disk('s3')->put("inbound/{$this->message->id}/" . ($this->message->mediaFilename() ?? $download->filename), $download->stream());
    }
}
```

A fuller job, with the rate limit and `retryUntil()`, is in [transporte.md](transporte.md).

For a command or a simple listener there is `awaitMedia()`, which blocks and retries for you:

```php
$download = $connection->awaitMedia($m);                          // sleeps 2, 5, 10, 20, 30 s between tries (67 s worst case)
$download = $connection->awaitMedia($m, waitsSeconds: [3, 3, 3]); // your own schedule
```

It returns the last answer: `attached`, `unavailable` as soon as Zapmizer says so, or `downloading` when the waits ran out — check `isDownloading()` and decide whether to give up. A **429** on the way is treated like a `downloading`, not thrown: it waits Zapmizer's `Retry-After` or the next entry of the schedule, whichever is longer, and asks again (still rate-limited when the schedule runs out → `downloading`). A **422** propagates as `MediaRejectedException`. The `$sleep` closure argument replaces the real sleep in tests.

The media endpoint is in every Zapmizer this version supports (1.148.10 or later, section 1).

### Per-connection webhook URL (opt-in)

On the shared route a connection is found by `X-Wid`. Every refused delivery (a forged signature, an orphan webhook, a forgotten secret) scans the whole table, and a valid delivery without `X-Wid`, or with another number of the team, reads half of it on average: each row read decrypts one or two secrets. With `zapmizer.webhook.per_connection` on, each connection made through the connect flow gets its own URL, `POST /zapmizer/webhook/{key}`. The key in the path finds the owner with one select, only that connection's secrets are tested, and events of another number are discarded.

Turn it on:

```env
ZAPMIZER_WEBHOOK_PER_CONNECTION=true
```

`true`, `1`, `on` and `yes` turn it on; anything else, absent included, leaves it off. The config merge is shallow: a `config/zapmizer.php` published before this version has no `per_connection` inside `webhook`, and the env var does nothing. Add it there, or re-publish the config:

```php
// config/zapmizer.php
'webhook' => [
    // ...
    'per_connection' => env('ZAPMIZER_WEBHOOK_PER_CONNECTION', false),
],
```

**Turn `zapmizer.connect.revoke_previous_token` on with it** (section 9). The two options are separate, but without the revocation the old webhooks keep delivering until they are revoked on Zapmizer.

**Run the migration first.** The key lives in a column of its own, `webhook_key` (nullable, unique), published only by its own tag:

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=zapmizer-migrations-webhook-key
php artisan migrate
```

With the option on and no column, `start` throws a `LogicException` (`zapmizer.webhook.per_connection needs the webhook_key column: publish --tag=zapmizer-migrations-webhook-key and migrate.`, a 500) before anything goes to Zapmizer, and a delivery to a keyed URL answers 500. On SQL Server a plain unique index refuses a second `NULL`: in the published migration, swap `->unique()` for a filtered unique index:

```php
use Illuminate\Support\Facades\DB;

Schema::table('zapmizer_connections', function (Blueprint $table) {
    $table->string('webhook_key', 64)->nullable();
});

DB::statement('CREATE UNIQUE INDEX zapmizer_connections_webhook_key_unique ON zapmizer_connections (webhook_key) WHERE webhook_key IS NOT NULL');
```

(Dropping the column on SQLite under Laravel 8 needs `doctrine/dbal`.)

**What the route accepts.** `POST {prefix}/webhook/{key}` (`zapmizer.webhook.connection`) sits in the same group as the shared route: same prefix and `zapmizer.routes.webhook_middleware`. `VerifyConnectionWebhookSignature` guards it, and only the connection's own secrets sign there, current and previous (a rotation keeps working). `zapmizer.webhook.secret` does not sign this route, and nothing unsigned is accepted, `verify_number.*` included: Zapmizer sends those to the verify-number webhook URL, never here. Every refusal before a valid signature is the same 401 (a malformed or unknown key, a connection without a secret, a wrong signature, missing headers, an old timestamp), with the usual `zapmizer: delivery refused by signature.` warning; the middleware never logs the key. An inactive connection answers 403, as on the shared route. Connections made before the option keep using the shared route.

**Events of another number are dropped.** When a connect on the same team picks another number, the key, and so the URL, stays: Zapmizer reuses the webhook and adds the new instance to it, and the URL receives the events of both numbers. A signed delivery whose body `wid` is not the connection's `phone_number` (the Brazilian ninth digit is matched both ways) answers 200 `Webhook Received`, fires no event and logs `zapmizer: delivery for another number discarded.` (info, with `connection_id` and `wid`). Only the body's `wid` counts: it is signed, the `X-Wid` header is not.

**A `connection` event without `wid`** (`qr_pending`, `connecting`) passes: the key found the connection. But it does not say which number it is about, so a listener should not change the connection's state on it; confirm it with the API first: `$connection->instanceClient()->connection($connection->bot_instance_id)` (`GET /bot-instances/{id}/connection`, what `show?live=1` calls).

**Moving the existing connections.** Turning the option on touches no row: a connection without a key keeps using the shared route, with the same middleware and the same events, with no deadline. The package cannot repoint a webhook on Zapmizer (the connect token is refused on `/webhooks`), so a connection moves by connecting again:

1. with `per_connection` and `revoke_previous_token` on, the customer connects again through the package: `start` issues a key and sends the new URL;
2. Zapmizer creates a webhook for it, with a secret; the row gets the key, the new webhook id and its secret;
3. the callback revokes the previous token. Zapmizer deletes the old webhook of the shared route only when no other live session uses it **and** the connect created it.

Otherwise the old webhook keeps delivering to the shared route, signed with a secret the row no longer holds: each delivery is a 401 after a scan. Grants left from earlier connects (or from before `revoke_previous_token`) are revoked on Zapmizer: the customer removes the access in their account, or the webhook is deleted on Zapmizer's "Webhooks" screen. There is no bulk command: a connect needs the customer in the popup.

**Do not turn it off after connections moved.** The route goes away, their deliveries get 404, and Zapmizer does not retry a 4xx: those events are lost. With `route:cache`, turning the option on or off needs the cache built again (`php artisan route:cache`).

**With `zapmizer.routes.enabled = false`**, mount the route yourself with the name `zapmizer.webhook.connection` **and** the parameter `zapmizerWebhookKey`, which the middleware reads. Without the route, `start` answers 500 (`route()` throws); without the parameter, every delivery answers 401.

```php
use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyConnectionWebhookSignature;

Route::post('zapmizer/webhook/{zapmizerWebhookKey}', [WebhookController::class, 'handleWebhook'])
    ->middleware(VerifyConnectionWebhookSignature::class)
    ->name('zapmizer.webhook.connection');
```

Also:

- An app that extends `VerifyWebhookSignature`: the new route uses `VerifyConnectionWebhookSignature`, so the overrides of your subclass do not apply there. To customize it, extend `VerifyConnectionWebhookSignature` and mount the routes.
- A subclass of the model (`zapmizer.models.connection`) that redefines `$hidden` must list `webhook_key`, which the package hides from serialization.
- A connection loaded with a select of your own that leaves `webhook_key` out gets a new key at every connect (the package does not see the stored one): the URL changes, and the secret with it.
- `registerWebhook()` registers the shared route (`route('zapmizer.webhook')`) unless you pass a URL. On a connection with a `webhook_key` (even with a `*` token), connect again instead of calling it.

## 7. Rotating the webhook secret

```php
$team->zapmizerConnection->rotateWebhookSecret();
```

Calls `POST /api/webhooks/{id}/secret` and stores both the new secret and the previous one — Zapmizer signs with both until the next rotation, so deliveries in flight keep validating.

That also means **rotating once does not revoke a leaked secret**: the previous one keeps signing (and validating) until the next rotation. To retire a compromised secret, rotate **twice**.

**Only with a full-access token.** Zapmizer refuses the `/webhooks` endpoints to a connect token — the one the connect flow stores — with 403 `missing_ability`. The same goes for `registerWebhook()` and `deleteRemoteWebhook()`. They work with a token that has every ability (`*`).

For a connection that came from the connect flow, replace a leaked secret by disconnecting (`destroy` revokes the token) and connecting again: with no other live grant on the same URL, Zapmizer creates the webhook again and hands a new secret. A [per-connection webhook URL](#per-connection-webhook-url-opt-in) keeps other grants off it.

## 8. Subscription and checkout

`PartnerClient` also reads a customer's subscription and creates its checkout. The customer is identified by the `external_id` you sent with the connect session: it exists on Zapmizer once the customer approves that connect, and before that both calls answer 404. The package's connect route sends `zapmizerExternalId()`.

Warning: Zapmizer matches `external_id` without regard to case (`Abc` and `abc` are the same customer), so the ids your app sends must be unique ignoring case.

Starting the connect, sending the `external_id`, and then the user to Zapmizer:

```php
use NotificationChannels\Zapmizer\Connect\PartnerClient;

$partner = app(PartnerClient::class);

$session = $partner->createSession(
    redirectUri: route('billing.connected'),
    state: $state,
    externalId: (string) $team->id,
);

return redirect()->away($session->url);
```

Later, once the customer approved the connect, reading the subscription and creating a checkout:

```php
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;

$partner = app(PartnerClient::class);

$subscription = $partner->subscription((string) $team->id);

if ($subscription === null) {
    // No approved connect with this external_id yet.
} elseif (!$subscription->hasAccess()) {
    try {
        return redirect()->away($partner->checkout((string) $team->id, route('billing.return'), $state)->url);
    } catch (ZapmizerApiException $e) {
        match ($e->error()) {
            ErrorCode::ALREADY_SUBSCRIBED => null, // read the subscription again
            ErrorCode::PAYMENT_INCOMPLETE => null, // the customer finishes the payment on Zapmizer
            default => throw $e,
        };
    }
}
```

- `subscription(string $externalId): ?PartnerSubscription` — `GET /api/partner/users/{externalId}`. `null` on 404. `PartnerSubscription` carries `userId`, `teamId`, `externalId` (as Zapmizer answered it), `subscribed`, `quantity` (`null` when Zapmizer did not send a number: unknown, not zero), `trialEndsAt` (`?CarbonImmutable`), `paymentIncomplete`, and `hasAccess(?DateTimeInterface $now = null)`: subscribed, or a trial that ends after `$now` (default: now).
- `checkout(string $externalId, string $redirectUri, ?string $state = null): PartnerCheckout` — `POST /api/partner/users/{externalId}/checkout`. Every call creates a new checkout. `PartnerCheckout` carries `url` and `expiresAt` (`?CarbonImmutable`, `null` when Zapmizer did not say). The customer comes back to `$redirectUri` with your `state` whether they paid or gave up: read `subscription()` again instead of trusting the return.
- `checkout()` refusals are `ZapmizerApiException`: 404 (`status()` 404, unknown `external_id`), 409 (`error()` `already_subscribed` or `payment_incomplete`; a code Zapmizer adds later arrives the same way), 422 (`errors()['redirect_uri']` when the redirect is not on your partner allowlist), 429 (`ZapmizerRateLimitedException`, `retryAfter()`). 401/403 are `PartnerCredentialsException` and 5xx or no answer `ZapmizerUnavailableException`, as in every partner call.
- `external_id` and `state` are checked before any request, in `createSession()` too: an `external_id` outside `/^[A-Za-z0-9_.-]{1,191}\z/` (letters, digits, `_`, `.`, `-`, 1 to 191 characters, no trailing newline), or `.`/`..`, and a `state` of `''` or `'0'`, or one that starts or ends with whitespace (Zapmizer trims it and drops it when empty), throw `InvalidArgumentException`.
- Zapmizer matches `external_id` without regard to case (`Abc` and `abc` are the same customer). Use ids that are unique ignoring case (numeric ids or lowercased ones are safest); `PartnerSubscription::$externalId` comes back as Zapmizer stored it, possibly with different case.
- Dates (`ConnectSession::$expiresAt`, `PartnerCheckout::$expiresAt`, `PartnerSubscription::$trialEndsAt`) are read only from ISO 8601 with a zone (`2026-09-07T01:00:00Z`, `…+00:00`, any number of decimals) and keep the zone they came in. A missing or empty value (`null` or `""`) is `null` with no warning; anything else unreadable is `null` and logs a warning `zapmizer: unreadable date.` with `field`, `value` and `external_id`; add your own context with `Log::withContext()`.
- There is no subscription webhook, and `partner/users/*` allows 60 requests a minute and 2,000 a day per partner: cache the answer in your app.

## 9. Reconnect and revoke

These calls act for one connection, with its token: get the client through `$connection->instanceClient()`. The package's routes do it (section 5: the reconnect, and the revocation on `destroy` and with `revoke_previous_token`); use the client for your own flow.

Embedding a conversation or the inbox has its own page, in Portuguese: [embed.md](embed.md).

### Reconnecting a number

```php
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;

$connection = $team->zapmizerConnection;

if ($connection->bot_instance_id === null) {
    // no paired number is stored (the pairing was forgotten): start a new connect instead
    return redirect()->route('numbers.connect');
}

try {
    $result = $connection->instanceClient()->reconnect($connection->bot_instance_id, route('numbers.reconnected'), $state);

    if ($result->needsClient()) {
        return redirect()->away($result->url);
    }
} catch (ZapmizerUnauthorizedException $e) {
    // the token is no longer valid: connect again
} catch (ZapmizerRateLimitedException $e) {
    // try again in $e->retryAfter() seconds
} catch (InstanceGoneException $e) {
    // the number does not exist, or it is not this connection's
} catch (ZapmizerApiException $e) {
    // 402 plan_limit / subscription_required, 403, 409, 422 errors()['redirect_uri']
} catch (ZapmizerUnavailableException $e) {
    // 5xx or no answer
} catch (ZapmizerConnectException $e) {
    // unexpectedResponse: a redirect, a body that is not JSON, an unsafe url
}
```

- `reconnect(int $botInstanceId, string $redirectUri, ?string $state = null, ?int $expiresIn = null): ReconnectResult` — `POST /api/bot-instances/{id}/reconnect`. The result comes from the HTTP status, not from the body: 200 `isOnline()`; 202 `isStarting()` (follow it with `connection()`); 409 `needs_reconnect` `needsClient()`, with `url` (send the customer there; Zapmizer brings them back to `$redirectUri` with your `state` and `status=reconnected` or `error`) and `expiresAt` (`?CarbonImmutable`, `null` when Zapmizer did not say).
- `state` and `expires_in` only matter on that 409: a 200 or 202 never comes back to `$redirectUri`, so discard the state. `state` is checked like in `createSession()` (`InvalidArgumentException` before any request), `expires_in` is kept between 900 and 86400, and both are only sent when not `null`.
- Refusals: 402 is a `ZapmizerApiException` with `error()` `plan_limit` (upgrade the plan) or `subscription_required` (checkout); read Zapmizer's text from `payload()['message']`. 403 has `status()` 403: without `error()` the token did not come from a connect, or the account's e-mail is not verified; `missing_ability` may come too. 404 is an `InstanceGoneException` with **two meanings**: the number does not exist, or it is not this connection's number. A 409 with another code (or none) is a `ZapmizerApiException` with that `error()`. 422 carries `errors()['redirect_uri']` when the redirect is not allowed for you. 429 is a `ZapmizerRateLimitedException` (10 a minute per number) with `retryAfter()`. A `needs_reconnect` without a safe `url` (below) is `unexpectedResponse`.

### Reading the number

```php
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;

$client = $connection->instanceClient();

try {
    $instance = $client->instance($connection->bot_instance_id);
} catch (InstanceGoneException $e) {
    // the number does not exist, or it is not this connection's
}

if (! $instance->isOnline) {
    // ask the reconnect: it decides whether turning the number on is enough
}

if ($instance->isOfficial()) {
    // Meta's API: free text only within 24 h of the contact's last message
}
```

- `instance(int $botInstanceId): BotInstance` — `GET /api/bot-instances/{id}`. This is how Zapmizer says to confirm whether the number is up: the call has no side effect and works on any account, including an official number, which has no `/connection` route. `BotInstance` has `id`, `state` (the state of Zapmizer's process, such as `ready` or `off`, not the pairing state of `connection()`), `stateLabel`, `isOnline`, `isUp`, `supportsGroups`, `supportsTemplates`, `createdAt` (`?CarbonImmutable`), `selfUrl` and `isOfficial()` (`supportsTemplates`: `true` on an official number). It does not carry the number; the connection's `phone_number` does. A flag Zapmizer did not send is `false`.
- `instances(): array` — `GET /api/bot-instances`, a list of `BotInstance`. With a connect token it holds only the connection's number, so only the first page is read.
- Errors: 401 `ZapmizerUnauthorizedException`; 404 `InstanceGoneException` (`instance()` only); 403 `missing_ability` (a token without `numbers:read`; the connect token has it) and the other 4xx `ZapmizerApiException`; 429 `ZapmizerRateLimitedException`; 5xx or no answer `ZapmizerUnavailableException`; a redirect, a body that is not JSON or one without `data` `unexpectedResponse`.

### Transcribing audio in a conversation

```php
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;

try {
    $settings = $connection->instanceClient()->updateConversationSettings($connection->bot_instance_id, '5581999998888', true);
} catch (ZapmizerApiException $e) {
    // 422: $e->errors()['wid']
}

$settings->wid;                 // '5581999998888@c.us', as Zapmizer normalized it
$settings->autoTranscribeAudio; // true
```

- `conversationSettings(int $botInstanceId, string $wid): ConversationSettings` — `GET /api/bot-instances/{id}/conversation-settings`. A conversation never adjusted answers the defaults.
- `updateConversationSettings(int $botInstanceId, string $wid, bool $autoTranscribeAudio): ConversationSettings` — `PUT` on the same route. With `true`, Zapmizer transcribes every audio received in that conversation, groups included, and delivers the text in the `message_transcribed` webhook event (it reaches your app through `WebhookReceived`). With and without the Brazilian ninth digit it is the same conversation.
- `$wid` is the contact (`5581999998888` or `...@c.us`) or the group (`...@g.us`); Zapmizer validates it (422 with `errors()['wid']`). `ConversationSettings` has `wid` (Zapmizer's normalized form) and `autoTranscribeAudio`.
- The connect token carries `messages:read` and `messages:create`, the abilities of the two routes, so it never gets a 403 here. A token without them gets `403 missing_ability`: a `ZapmizerApiException` with `error() === ErrorCode::MISSING_ABILITY`. 401 `ZapmizerUnauthorizedException`, 404 `InstanceGoneException` (the number does not exist or is not this connection's), 429 `ZapmizerRateLimitedException`, 5xx `ZapmizerUnavailableException`.

### Revoking the token

```php
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerException;

// The customer removes the integration: the connection's own token is the one to revoke.
try {
    $connection->instanceClient()->revokeToken();
} catch (ZapmizerException $e) {
    report($e);
}

$connection->delete();

// Replacing a token with the one from a new connect: revoke the PREVIOUS one, with a client built for it.
// instanceClient() reads api_token when it is called, so after the new token is stored it would revoke the new one.
$previousToken = $connection->api_token;
// ... the new token is stored on $connection here ...

try {
    app(InstanceClient::class, [
        'api_token' => $previousToken,
        'api_version' => config('zapmizer.api_version'),
    ])->revokeToken();
} catch (ZapmizerException $e) {
    report($e);
}
```

- `revokeToken(): void` — `DELETE /api/connect/token` revokes the token the client authenticates with. Use it when the customer removes the integration, and to revoke the previous token after a new connect replaced it (the client must be built for the previous token, as above: `instanceClient()` uses whichever `api_token` is stored when it is called, so calling it after the swap revokes the new token and leaves the old one valid). The package's routes do both: `destroy` revokes the connection's token, and with `zapmizer.connect.revoke_previous_token` on the connect callback revokes the token the connection held before (below). Zapmizer also points to it to recover a lost webhook secret.
- It returns on 204 (revoked now) and on 401 (the token was already revoked, or is not valid). A 404 is a `ZapmizerApiException` with `status()` 404: the token did not come from a connect and **is still valid**. A 403 (e-mail not verified) also means nothing was revoked. 5xx or no answer is a `ZapmizerUnavailableException`, a 429 a `ZapmizerRateLimitedException`, and a redirect or another 2xx `unexpectedResponse`.
- 401 and 404 also come from a wrong `base_uri` or environment (an unknown route answers 404). Never let a failed revocation block the removal: catch it, report it and go on.

### The package's revocations

`destroy` always revokes the connection's token. The connect callback revokes the token the exchange handed out but did not store (an orphan, section 5) and, with `zapmizer.connect.revoke_previous_token` on, the token the connection held before, once the new one is saved — never the new one, and nothing when both are the same. Each is one attempt:

| `DELETE /api/connect/token` answers | Effect |
|---|---|
| 204 | Revoked; nothing logged. |
| 401 | Already revoked, or not valid; nothing logged. |
| 404 | The token did not come from a connect and **is still valid**: warning with `status` 404. |
| 403, 429, 5xx, no answer, a redirect, another 2xx | Warning with `status` (when there is one) and the exception's message. |

A failed revocation never changes the popup's status nor stops `destroy`: the new token is already stored (or the row goes anyway), and the cost is one more grant on Zapmizer, which the warning names. The warnings are `zapmizer: the previous token was not revoked.` (`connection_id`), `zapmizer: the orphan token was not revoked.` (`team_id`) and, on `destroy`, `zapmizer: the token was not revoked and is still valid.` (404) or `zapmizer: the token was not revoked.`. A token that no longer decrypts is not sent: `zapmizer: could not read the previous token to revoke it.` / `zapmizer: could not read the token to revoke it.`.

`revoke_previous_token` is **off by default**. Off, each new connect leaves the previous grant valid, and after a team switch the old webhook keeps delivering (a 401 in the log for each). Turn it on:

```env
ZAPMIZER_CONNECT_REVOKE_PREVIOUS_TOKEN=true
```

`true`, `1`, `on` and `yes` turn it on; anything else, absent included, leaves it off.

The config merge is shallow: a `config/zapmizer.php` published before this version has no `revoke_previous_token` inside `connect`, and the env var does nothing. Add `'revoke_previous_token' => env('ZAPMIZER_CONNECT_REVOKE_PREVIOUS_TOKEN', false),` inside `connect` in the published config, or re-publish it.

**When you turn it on, remove the `updating` observer** the previous version of this guide taught for revoking the previous token: otherwise the token is revoked twice, and the second `DELETE` logs.

### Catch order

`ZapmizerUnauthorizedException`, `ZapmizerRateLimitedException` and `InstanceGoneException` extend `ZapmizerApiException`: catch them before it. `unexpectedResponse` and `ZapmizerUnavailableException` are `ZapmizerConnectException` but not `ZapmizerApiException`, so a `catch (ZapmizerApiException)` lets them through. None of them renders a response.

`reconnect()` (like `createSession()` and `checkout()`) throws `InvalidArgumentException` before any request when the `state` is invalid; that is not a `ZapmizerException`, so no `catch` for the exceptions above covers it.

## Error handling

```php
use NotificationChannels\Zapmizer\Exceptions\ZapmizerException;             // root of every exception of the package
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;      // base of the connect flow and of sending
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;          // Zapmizer refused the call (4xx) — ->status(), ->error(), ->reason(), ->errors(), ->payload()
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;  // 429 — ->retryAfter()
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException; // team token revoked or missing (401)
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;   // partner key refused (401/403)
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;         // instance deleted or not this connection's (404 on connection(), instance(), reconnect() and the conversation settings)
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;        // media request refused (422) — ->reason()
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;     // media endpoint rate limit (429) — ->retryAfter()
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;  // timeout / 5xx / no answer
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;        // resolver has nothing to connect
```

None of them renders a response: the routes above answer their JSON codes themselves, and anywhere else your exception handler decides. [erros.md](erros.md) (in Portuguese) shows how to read a `ZapmizerApiException` and the `ErrorCode` constants.

The clients behind the controller (`Connect\PartnerClient`, `Connect\InstanceClient`) are container-bound with the configured transport, `LaravelHttpTransport` by default — see [transporte.md](transporte.md). Fake them in tests with `Http::fake()`. Every client sends `Accept: application/json` and does **not** follow redirects: a revoked token makes Zapmizer redirect to its login page, and a redirect or a non-JSON answer is an exception (`unexpectedResponse`) instead of a silent "success". `InstanceClient` always acts for one connection: its token is mandatory, there is no fallback to `zapmizer.api_token` — get it through `$connection->instanceClient()`. It knows `connection($id)`, `instance($id)`, `instances()`, `conversationSettings($id, $wid)` and `updateConversationSettings($id, $wid, $on)` (section 9), `createWebhook($url)`, `rotateWebhookSecret($id)`, `deleteWebhook($id)` (these three with a full-access token only, section 7), `media($botInstanceId, $messageId, $timestamp)`, `reconnect()` and `revokeToken()` (section 9), `conversationSession()` and `inboxSession()` ([embed.md](embed.md)) — instances are created and paired on Zapmizer's page, not from here. `media()` is the one endpoint whose 200 is not JSON (the bytes); its 202/404 answers are, and a redirect is still refused; its 422 (bad request, Meta Cloud instance) is a `MediaRejectedException` and its 429 (rate limit) a `MediaRateLimitedException`. On `InstanceClient`, any other 4xx is a `ZapmizerApiException` (401 `ZapmizerUnauthorizedException`, 429 `ZapmizerRateLimitedException`), except the 404 of `connection()`, `instance()`, `reconnect()` and the conversation settings (`InstanceGoneException`), of `deleteWebhook()` (done) and of `media()` (`unavailable`), the 409 `needs_reconnect` of `reconnect()` (a result) and the 401 of `revokeToken()` (done). On `PartnerClient`, a refused partner credential (401/403) is a `PartnerCredentialsException`, `exchangeCode()` and `subscription()` return `null` on 404 (unknown, expired or already used code; no approved connect with that `external_id`), and any other 4xx is a `ZapmizerApiException` (429 `ZapmizerRateLimitedException`). A 4xx of a partner call is logged as `zapmizer: partner call failed.`, except 404 and 409, which are business answers (no connect yet, already subscribed, payment pending), and 429, logged as the warning `zapmizer: partner call rate-limited.` with `endpoint` and `retry_after`, without the body.

`PartnerClient::createSession($redirectUri, $state = null, $webhookUrl = null, $expiresIn = null, $externalId = null)` keeps `expires_in` between Zapmizer's floor of **900 seconds** (`PartnerClient::MIN_EXPIRES_IN`) and its ceiling of **86400** (`PartnerClient::MAX_EXPIRES_IN`): the same signature covers the page, the authorization and the QR code, and a shorter one died mid-pairing. `ConnectToken` carries `userId` and `teamId` (both `int`) and `hasNumber()`: `false` when `phone_number` or `bot_instance_id` came `null` (the number ceased to exist between the approval and the exchange; Zapmizer says to start a new session).

## Customization summary

| Config key | Purpose |
|---|---|
| `zapmizer.partner.id` / `secret` | partner credentials for the connect flow |
| `zapmizer.api_version` | `api-version` header, sent by every client when set |
| `zapmizer.default_country_code` | country code completed on national numbers (default `55`) |
| `zapmizer.connect.resolver` | which model a request connects |
| `zapmizer.connect.state_ttl_minutes` | how long the popup has to come back, also sent as `expires_in` (default 30) |
| `zapmizer.connect.revoke_previous_token` | revoke the token a connection held before when a new connect replaces it (default off; recommended on) |
| `zapmizer.webhook.secret` | the single-tenant webhook secret (`ZAPMIZER_WEBHOOK_SECRET`) |
| `zapmizer.webhook.tolerance` | accepted timestamp drift in seconds (default 300) |
| `zapmizer.webhook.per_connection` | each connection made through the connect flow gets its own webhook URL (`ZAPMIZER_WEBHOOK_PER_CONNECTION`, default off; [section 6](#per-connection-webhook-url-opt-in)) |
| `zapmizer.models.connection` | subclass the connection model |
| `zapmizer.routes.*` | enable/prefix/middleware of the package routes |

On the model, `zapmizerExternalId()` (section 3). For behaviour after a connect or a refused reconnect, the `NumberConnected` and `ReconnectRefused` events (section 5).

## Upgrading from 0.3.x

The partner client changed, and so did the connect flow ("Connect flow" below).

- Needs Zapmizer **1.148.10 or later** (section 1).
- `PartnerClient::createSession($redirectUri, $state = null, $webhookUrl = null, $expiresIn = null, $externalId = null)`: `state` became optional and `externalId` was added at the end. Named and positional calls keep working; a subclass that overrides `createSession()` must update its signature.
- `createSession()`, `subscription()` and `checkout()` throw `InvalidArgumentException` for an invalid `external_id` or `state` (`""`, `"0"` or whitespace at either end) before calling Zapmizer.
- `createSession()` caps `expires_in` at 86400, and sends an empty `redirect_uri`/`webhook_url` (Zapmizer answers 422) instead of dropping it.
- `ConnectSession::$expiresAt` is a `?CarbonImmutable` instead of a `?string`. The `start` JSON normalizes it: `2026-09-07T01:00:00Z` becomes `2026-09-07T01:00:00+00:00`, without fractions of a second.
- `ConnectToken`: `teamId` is an `int` (never `null`), `userId` was added as the second constructor argument, and an answer without a valid `user_id`/`team_id` throws `unexpectedResponse`. The callback then reports `exchange_failed` and stores nothing (it used to store the connection with a `null` `zapmizer_team_id`).
- A partner call answered 404 or 409 no longer logs `zapmizer: partner call failed.`.

**Connect flow.**

| Before | Now |
|---|---|
| `Contracts\Connectable` without `zapmizerExternalId()` | required; models using the trait need no change |
| `start` sent neither `external_id` nor `expires_in` | it sends both; the hosted link lasts the TTL |
| `state_ttl_minutes` defaulted to 10; `null` gave 0 | defaults to 30; `null`/invalid means 30. A published config keeps its 10 |
| `?error=subscription_required` → `exchange_failed` | `subscription_required` |
| status `qr_unavailable` | gone (Zapmizer has not sent it since 1.148.8) |
| `?error=` answered before the `state` | the `state` is checked first; with an invalid one, `invalid_state` |
| a callback with a wrong `state` spent the pending one | the pending state only goes when the `state` matches |
| a token without a number stored inactive (or active without an instance) | `exchange_failed`, nothing stored, the token revoked |
| `team_already_connected` left the new token valid | the new token is revoked |
| an invalid `bot_instance_id`/`webhook_id` became 0 | `exchange_failed` |
| a 429 on the exchange → `exchange_failed` | the popup waits and retries; `rate_limited` when it cannot |
| a 429 of a partner call: `Log::error` with the body | `Log::warning` without the body |
| `webhook_failed` = inactive | `webhook_failed` = active, sends, no secret to receive; new message ("Connected and sending, but incoming messages can't be verified…"); the `ConnectButton` stub emits `connected` and shows the warning |
| `destroy` did not revoke | `destroy` revokes the token |
| `APP_KEY` changed: callback `exchange_failed`, `destroy` and `show?live=1` 500 | the unreadable value counts as absent, with a warning; the next connect overwrites it (team switch included); `show?live=1` answers `state: reauth_required` |
| the `connect-result` view also served a reconnect you built | a new `reconnect-result` view |

The callback and `destroy` no longer call `/webhooks` (rotation, remote deletion): with a connect token they always failed with 403 `missing_ability`, left the connection inactive, and the next connect repeated the failure.

- **Turn `revoke_previous_token` on** (`ZAPMIZER_CONNECT_REVOKE_PREVIOUS_TOKEN=true`), and remove the `updating` observer the previous version of this guide taught. A published `config/zapmizer.php` has no such key inside `connect` (the merge is shallow), so the env var alone does nothing: add `'revoke_previous_token' => env('ZAPMIZER_CONNECT_REVOKE_PREVIOUS_TOKEN', false),` inside `connect`, or re-publish the config.
- A `config/zapmizer.php` you published keeps `'state_ttl_minutes' => (int) env('ZAPMIZER_CONNECT_STATE_TTL', 10)`: raise it to 30, or delete the key to take the package's default.
- Re-publish the stubs with `php artisan vendor:publish --tag=zapmizer-wizard --force`. `--force` overwrites your local edits of the published files: save them first and apply them again.
- A subclass of `ConnectController`: `obtainWebhookSecret()` and `forgetRemoteWebhook()` are gone, and the state methods moved to the `HandlesPopupState` trait with the same signatures. `popupStatus()` reads `static::POPUP_ERRORS`. An own `result()` with a map of messages now receives `subscription_required` and `rate_limited` (and no longer `qr_unavailable`): add both keys.

## Upgrading from 0.1.x

**Breaking** (0.x: a minor bump is the breaking bump). The pairing moved to Zapmizer's hosted page, and the wizard on your side went with it.

- **Requires a Zapmizer with the hosted pairing** (for this version, 1.148.10 or later: section 1). Against an older Zapmizer the callback still lands with the token, but with no number: the connection is stored inactive and there is no longer a wizard to pair it. With this release, a token exchange that does not answer `user_id`/`team_id` (Zapmizer before 1.148.8) makes the callback report `exchange_failed` and store nothing.
- **Routes removed:** `zapmizer.connect.instance`, `zapmizer.connect.instances`, `zapmizer.connect.connection` (404 now). Response codes that went with them (`reauth_required`, `choice_required`, `booting`, `not_connected`, `no_instance`, `plan_limit` 422, `instance_unavailable`, `qr_not_available`) are gone; `plan_limit` and `qr_unavailable` are now **postMessage statuses** of the callback, alongside the new `webhook_failed`.
- **The callback activates the connection.** It used to store an inactive token for the wizard to pair; now it stores number, instance, webhook id and secret, and `is_active = true`. Any code that waited for `connection` polling to activate has nothing to wait for.
- **`show` gained `?live=1`** — the only way left to ask Zapmizer whether the number is online.
- **Stubs removed:** `ConnectionWizard.vue`, `StepAuthorize.vue`, `StepQr.vue`, `StepDone.vue`, `InstancePicker.vue`, `QrCanvas.vue`, `useZapmizerConnection.ts`. `StepAuthorize` became `ConnectButton.vue`; `ConnectionPanel.vue` and `useZapmizerIntegration.ts` were rewritten for the new shape; `types/zapmizer.ts` lost `ZapmizerInstanceConnection` and `ZapmizerInstance`. The `qrcode` npm dependency is no longer needed. Re-publish with `--tag=zapmizer-wizard` (the tag kept its name) and delete the old files from `resources/js`.
- `PartnerClient::createSession()` gained `$webhookUrl` as the **third** argument — `$expiresIn` moved to fourth — and floors `expires_in` at 900.
- `Connect\InstanceClient` lost `instances()` and `createInstance()`; `Connect\InstanceSummary`, `Exceptions\InstanceBootingException` and `Exceptions\InstancePlanLimitException` are gone.
- `ConnectToken` gained `phoneNumber`, `botInstanceId`, `webhookId`, `webhookSecret` and `needsWebhookSecret()`.
- Not changed: the table, the model, the trait, the signed webhook, `registerWebhook()` / `rotateWebhookSecret()` / `deleteRemoteWebhook()`, `destroy`, the resolver contract, `team_already_connected`.

## Upgrading from 0.0.x

The webhook route changed behaviour. Read this even if you never touch the connect flow.

**Nothing new is required for a single-tenant application.** `ZAPMIZER_API_TOKEN` + `ZAPMIZER_FROM_NUMBER` keep sending; the `zapmizer_connections` migration is only needed by the connect flow — the package checks whether the table exists instead of assuming it.

What changed on `POST /zapmizer/webhook`:

- **Breaking — bot events must be signed.** `message` and every other bot event are only accepted with a valid `X-Zapmizer-Signature`. Zapmizer signs every webhook it has (every webhook there has a secret, backfilled for the old ones), so this only breaks a webhook that Zapmizer is *not* the sender of. To keep receiving them: copy the webhook's secret from Zapmizer's "Webhooks" screen into `ZAPMIZER_WEBHOOK_SECRET`. Without it, every bot event is a 401 with a log line — you will see it.
- **Not breaking — `verify_number.*` keeps arriving unsigned** and keeps being accepted, exactly as before. Zapmizer sends those outside the bot, without a signature.
- There is no flag to accept unsigned bot events: nothing on Zapmizer's side is unsigned except `verify_number.*`, so a flag would only open the route to forged messages.
- `WebhookReceived` / `WebhookHandled` gained a second, optional constructor argument (`$connection`). Listeners are unaffected; a subclass overriding the constructor is not.
- A body that is not JSON now answers 401 (unsigned, unverifiable) instead of 400.

New, opt-in: the connect flow (this document), `MessageReceived`, `zapmizer.default_country_code` (`55`, the previous hard-coded behaviour).
