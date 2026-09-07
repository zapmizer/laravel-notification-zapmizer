# Connecting a WhatsApp number per model (multi-tenant)

This guide wires the Zapmizer **connect flow** into a Laravel application: each model of yours (a `Team`, a `User`, ...) authorizes its own Zapmizer account through a hosted popup, pairs a WhatsApp number by QR code, and from then on sends from — and receives on — that number. It mirrors Laravel Cashier: a `Connectable` trait on the model, a package-owned table, a controller with stable response codes, and events your app listens to.

The single-tenant setup (`ZAPMIZER_API_TOKEN` + `ZAPMIZER_FROM_NUMBER`) keeps working — `Connectable` is an addition, not a replacement.

## How it works

```
your app ──(X-Partner-Key)──> POST /api/connect/sessions ──> { url }      [popup]
user authorizes a team on the hosted page ──> redirect to your callback with ?code&state
your app ──(X-Partner-Key)──> POST /api/connect/token { code } ──> { token, team_id, team_name }
your app ──(team token)──> POST /api/bot-instances / GET .../connection     [QR code polling]
state === connected ──> number stored, connection activated,
                        webhook registered on Zapmizer ──> { secret } stored (encrypted)
Zapmizer ──(signed)──> POST /zapmizer/webhook ──> MessageReceived event
```

## 1. Zapmizer-side setup

You need **partner credentials** (id + secret), issued by Zapmizer for your application. They authenticate the hosted connect flow; each connected team's own token is obtained through it and stored by the package.

## 2. Package setup

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=config
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=migrations
php artisan migrate
```

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

```php
$team->zapmizerMessage('5511999999999')->text('Hello')->send();
```

Both `zapmizer()` and `zapmizerMessage()` throw `ZapmizerConnectException` when the model isn't connected.

The `ZapmizerConnection` model (`zapmizer.models.connection` to subclass it) exposes: `api_token`, `zapmizer_team_id`, `zapmizer_team_name`, `phone_number`, `bot_instance_id`, `connected_at`, `webhook_id`, `webhook_secret`, `webhook_previous_secret`, `is_active`. The token and both secrets are `encrypted` casts and hidden from serialization; `api_token_masked` (`••••1234`, or `••••` when the stored token no longer decrypts) is what a screen gets. `hasApiToken()` / `isConnected()` check the raw attribute and never decrypt, so a token written with an old `APP_KEY` shows as connected and fails loudly at send time — not on every `toArray()`. Do **not** add an accessor over those attributes — it would win over the cast and return the ciphertext.

## 4. Who gets connected: the resolver

The controller asks a resolver which model the current request connects. The default returns `$request->user()` (which must implement `Contracts\Connectable`, or it throws a `ZapmizerConnectException` naming the class). For team-scoped connections, point `zapmizer.connect.resolver` at your own — the return type is the contract:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;

class ResolvesCurrentTeam implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        return $request->user()->currentTeam;
    }
}
```

```php
// config/zapmizer.php
'connect' => ['resolver' => App\Zapmizer\ResolvesCurrentTeam::class],
```

The state stored at `start` is bound to the resolved model, so a callback for another model is refused (`invalid_state`).

## 5. The wizard

### Routes

All under the package prefix (`zapmizer`), behind `web` + `auth`:

| Route name | Method | Returns |
|---|---|---|
| `zapmizer.connect.show` | GET | `{ connection }` — the model's connection, or `null`. |
| `zapmizer.connect.start` | POST | `{ url, expires_at }` — open `url` in a popup. Stores the `state` in the session. |
| `zapmizer.connect.callback` | GET | HTML page that `postMessage`s `{ source: 'zapmizer-connect', status, message }` to the opener and closes — it never answers a 500 (that would leave the wizard waiting). `status`: `ok`, `denied`, `invalid_state`, `exchange_failed`, `team_already_connected`. Stores the token; the connection is born **inactive**. Re-authorizing the **same** Zapmizer team keeps the paired number and instance; a **different** team resets number, instance and webhook (the old webhook is deleted over there, best-effort). |
| `zapmizer.connect.instance` | POST | Resolve the instance to pair. Body: `{ instance_id }` to adopt a chosen one, `{ create: true }` to create, nothing to let the backend decide (reuse the stored one — booting it again if it is `disconnected`/`off` → adopt the only connected one → create). |
| `zapmizer.connect.instances` | GET | `{ instances: [{ id, number, is_current }] }` — connected instances, for the choice screen. |
| `zapmizer.connect.connection` | GET | `{ connection: { id, state, qrcode, qrcode_expires_at, number, ... } }` — poll this until `state === 'connected'`. |
| `zapmizer.connect.destroy` | DELETE | Deletes the webhook on Zapmizer (best-effort: a failure is logged and the local row goes anyway) and the local connection with its credentials. Zapmizer has no endpoint to revoke the team token — it is forgotten here, not revoked there. |

### Response codes

Every branch the frontend has to take carries a stable `code`:

| `code` | HTTP | Meaning |
|---|---|---|
| `reauth_required` | 200 | The stored token was revoked on Zapmizer — send the user back to step 1. |
| `choice_required` | 200 | 2+ instances connected — show `instances` and POST `instance_id`. |
| `booting` | 202 | An instance boot is in progress — retry the same request after a delay. |
| `not_connected` | 409 | No token yet — step 1 was not completed. |
| `no_instance` | 409 | No instance resolved (or it was deleted on Zapmizer) — POST `instance` again. |
| `plan_limit` | 422 | The Zapmizer plan has no room for another instance (`message` is user-facing). |
| `instance_unavailable` | 422 | The chosen instance dropped — `instances` carries the refreshed list. |
| `qr_not_available` | 422 | The instance was created but Zapmizer refuses its connection endpoint — the team has no QR-code connections enabled. Retrying does not help; the id is kept so a retry does not create another. |
| `zapmizer_unavailable` | 503 | Zapmizer is down or misbehaving — back off and retry. |
| `partner_unauthorized` | 503 | Your partner credentials were refused — configuration error, logged. |

`booting` (202) reuses the instance Zapmizer reports as booting (`bot_instance_id` in its 423 body): it is stored right away, so the retry polls it instead of creating a second one.

When the polled state reaches `connected`, the package stores the number, activates the connection and registers the webhook pointing at `route('zapmizer.webhook')`, storing the secret Zapmizer returns (it only ever returns it once). Registration runs on a row lock, so the `instance` call and the `connection` poll overlapping never register twice. A failed registration is logged and retried on the next poll.

### Inertia + Vue components

A ready wizard (authorize → QR → done), a connection panel and the polling composables ship as publishable stubs, Jetstream-style:

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=zapmizer-wizard
```

This copies into `resources/js/`:

- `components/zapmizer/ConnectionWizard.vue`, `StepAuthorize.vue`, `StepQr.vue`, `StepDone.vue`, `InstancePicker.vue`, `QrCanvas.vue`, `ConnectionPanel.vue`
- `composables/useZapmizerConnection.ts` (polling with backoff, handles every code above), `useZapmizerIntegration.ts` (loads the state, decides the initial step)
- `types/zapmizer.ts`

They expect `axios`, `lucide-vue-next`, `qrcode` and Ziggy's `route()`; the markup uses utility classes from the app they came from (`gp-*`) — restyle them as yours. The result page of the popup is a Blade view you can publish with `--tag=views` (`resources/views/vendor/zapmizer/connect-result.blade.php`).

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
- **each `ZapmizerConnection`'s secret** — registered by the connect flow. The connections whose `phone_number` matches `X-Wid` are queried first; only on a miss is the whole table scanned (a signed delivery is never refused because `X-Wid` lied — the proof is the HMAC). An application that never published the `zapmizer_connections` migration skips this leg: the table's existence is checked (and cached once true), not assumed.

It accepts a 5-minute clock drift (`zapmizer.webhook.tolerance`), refuses with **401 and a log line** (reason, wid, timestamp, ip — never the signature), and refuses an inactive connection with 403. A credential that no longer decrypts (`APP_KEY` changed) only takes its own connection out of the matching.

**Unsigned deliveries** are accepted for **`verify_number.*` only** — Zapmizer sends those outside the bot, without a signature, by construction. A `message` (or any other event) without the signature headers is refused with 401: it was not sent by Zapmizer. There is no flag to turn this off. If you rely on `verify_number.*`, note that anyone can POST one — harden `zapmizer.routes.webhook_middleware` with an IP allowlist or a throttle (see [docs/verify-number.md](verify-number.md#6-the-webhook)).

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
    $m->hasMedia; $m->mediaMetadata;   // mimetype, filename, caption, size, ... (bytes are not delivered)
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

## 7. Rotating the webhook secret

```php
$team->zapmizerConnection->rotateWebhookSecret();
```

Calls `POST /api/webhooks/{id}/secret` and stores both the new secret and the previous one — Zapmizer signs with both until the next rotation, so deliveries in flight keep validating.

That also means **rotating once does not revoke a leaked secret**: the previous one keeps signing (and validating) until the next rotation. To retire a compromised secret, rotate **twice**.

## Error handling

```php
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;      // base
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;   // partner key refused (renders 503 partner_unauthorized)
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;  // timeout / 5xx (renders 503 zapmizer_unavailable)
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException; // team token revoked (401)
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;         // instance deleted (4xx)
use NotificationChannels\Zapmizer\Exceptions\InstanceBootingException;      // 423 (`$instanceId` when Zapmizer names the booting instance)
use NotificationChannels\Zapmizer\Exceptions\InstancePlanLimitException;    // 402
```

The clients behind the controller (`Connect\PartnerClient`, `Connect\InstanceClient`) are container-bound with an injected Guzzle client, like `VerificationClient` — swap `GuzzleHttp\Client` in the container to fake them in tests. `InstanceClient` always acts for one connection: its token is mandatory, there is no fallback to `zapmizer.api_token` — get it through `$connection->instanceClient()`.

## Customization summary

| Config key | Purpose |
|---|---|
| `zapmizer.partner.id` / `secret` | partner credentials for the connect flow |
| `zapmizer.api_version` | `api-version` header, sent by every client when set |
| `zapmizer.default_country_code` | country code completed on national numbers (default `55`) |
| `zapmizer.connect.resolver` | which model a request connects |
| `zapmizer.connect.state_ttl_minutes` | how long the popup has to come back (default 10) |
| `zapmizer.webhook.secret` | the single-tenant webhook secret (`ZAPMIZER_WEBHOOK_SECRET`) |
| `zapmizer.webhook.tolerance` | accepted timestamp drift in seconds (default 300) |
| `zapmizer.models.connection` | subclass the connection model |
| `zapmizer.routes.*` | enable/prefix/middleware of the package routes |

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
