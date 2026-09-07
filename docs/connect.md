# Connecting a WhatsApp number per model (multi-tenant)

This guide wires the Zapmizer **connect flow** into a Laravel application: each model of yours (a `Team`, a `User`, ...) authorizes its own Zapmizer account **and pairs a WhatsApp number** in a single hosted popup, and from then on sends from — and receives on — that number. It mirrors Laravel Cashier: a `Connectable` trait on the model, a package-owned table, a controller with stable response codes, and events your app listens to.

The single-tenant setup (`ZAPMIZER_API_TOKEN` + `ZAPMIZER_FROM_NUMBER`) keeps working — `Connectable` is an addition, not a replacement.

## How it works

```
your app ──(X-Partner-Key)──> POST /api/connect/sessions { redirect_uri, state, webhook_url } ──> { url }   [popup]
user authorizes a team AND pairs a number (QR code) on the hosted page
        ──> redirect to your callback with ?code&state   (or ?error=access_denied|plan_limit|qr_unavailable)
your app ──(X-Partner-Key)──> POST /api/connect/token { code }
        ──> { token, team_id, team_name, phone_number, bot_instance_id, webhook_id, webhook_secret }
everything stored (token and secret encrypted), connection ACTIVE
Zapmizer ──(signed)──> POST /zapmizer/webhook ──> MessageReceived event
```

There is no wizard on your side: no instance creation, no QR code rendering, no polling. The popup opens, the user comes back, the connection is ready. Your screen needs one button and one panel.

## 1. Zapmizer-side setup

You need **partner credentials** (id + secret), issued by Zapmizer for your application. They authenticate the hosted connect flow; each connected team's own token is obtained through it and stored by the package.

**This flow needs a Zapmizer with the hosted pairing** — `POST /api/connect/sessions` accepting `webhook_url` and the token exchange answering `phone_number`/`bot_instance_id`/`webhook_id`/`webhook_secret`. That is Zapmizer **1.149.0 or later** (the release after `connect-pareamento`). Against an older Zapmizer the callback still stores the token, but no number comes and the connection stays inactive — see "Upgrading from 0.1.x".

Zapmizer validates `webhook_url` the way it validates `redirect_uri`: **https in production**, host on your partner allowlist, public address (anti-SSRF). The package sends `route('zapmizer.webhook')` — make sure `APP_URL` resolves to the public https host, and that this host is on your partner allowlist over there.

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

```php
$team->zapmizerMessage('5511999999999')->text('Hello')->send();
```

Both `zapmizer()` and `zapmizerMessage()` throw `ZapmizerConnectException` when the model isn't connected.

The `ZapmizerConnection` model (`zapmizer.models.connection` to subclass it) exposes: `api_token`, `zapmizer_team_id`, `zapmizer_team_name`, `phone_number`, `bot_instance_id`, `connected_at`, `webhook_id`, `webhook_secret`, `webhook_previous_secret`, `is_active`. All of them are filled by the callback in one go. The token and both secrets are `encrypted` casts and hidden from serialization; `api_token_masked` (`••••1234`, or `••••` when the stored token no longer decrypts) is what a screen gets. `hasApiToken()` / `isConnected()` check the raw attribute and never decrypt, so a token written with an old `APP_KEY` shows as connected and fails loudly at send time — not on every `toArray()`. Do **not** add an accessor over those attributes — it would win over the cast and return the ciphertext.

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
| `zapmizer.connect.start` | POST | `{ url, expires_at }` — open `url` in a popup (520×720 works). Stores the `state` in the session and sends `route('zapmizer.webhook')` as the session's `webhook_url`. |
| `zapmizer.connect.callback` | GET | HTML page that `postMessage`s `{ source: 'zapmizer-connect', status, message }` to the opener and closes — it never answers a 500 (that would leave the opener waiting). Stores token, team, number, instance and webhook; the connection is born **active**. |
| `zapmizer.connect.destroy` | DELETE | Deletes the webhook on Zapmizer (best-effort: a failure is logged and the local row goes anyway) and the local connection with its credentials. Zapmizer has no endpoint to revoke the team token — it is forgotten here, not revoked there. |

### The callback's `status`

Every outcome the popup reports has a stable `status` — the message that goes with it is a fallback, not a contract:

| `status` | Meaning |
|---|---|
| `ok` | Connected: token, number and webhook stored, connection active. Reload `show`. |
| `denied` | The user cancelled on the hosted page. |
| `plan_limit` | The Zapmizer plan has no room for another number. The user frees one up (or upgrades) over there and tries again. |
| `qr_unavailable` | The Zapmizer team cannot pair by QR code. Retrying does not help until the team is fixed over there. |
| `invalid_state` | The session `state` is missing, mismatched, bound to another model or expired (`zapmizer.connect.state_ttl_minutes`). |
| `exchange_failed` | The code is unknown/used, the partner key was refused, Zapmizer is down or answered something that is not JSON — details in the log. Nothing was stored. |
| `webhook_failed` | Number paired and stored, but the webhook secret could not be obtained (below). The connection is stored **inactive**; connecting again fixes it. |
| `team_already_connected` | Another model here already holds this Zapmizer team. One connectable per team — two would receive every message twice. |
| `no_connectable` | The resolver found nothing to connect on this request. |

Re-authorizing the **same** Zapmizer team overwrites number and instance with what the new pairing brought, and keeps the stored webhook secret when Zapmizer reused the same webhook. A **different** team resets everything: the old webhook is deleted over there (best-effort) and the new team's number, instance and webhook take over.

### The webhook secret

Zapmizer registers the webhook for the session's `webhook_url` on the team while pairing, and hands the id and secret back with the token. The secret is only ever given **once**, on creation — so when the team **already had** a webhook for that URL (a reconnection, or a manual registration), Zapmizer reuses it and answers `webhook_secret: null`. The package then:

- keeps the secret it already has, when the reused webhook is the one stored on the connection (same `webhook_id`);
- otherwise **rotates** (`POST /api/webhooks/{id}/secret`) right away to get a valid pair — without a secret the connection could verify no delivery at all. A failed rotation leaves the connection **inactive**, logs the reason and reports `webhook_failed`.

### `show?live=1`

The panel that shows the connected number usually wants to say whether it is online. `GET zapmizer/connect?live=1` queries `GET /api/bot-instances/{id}/connection` with the connection's token and folds the answer into `connection`:

| `connection.state` | `is_online` | Meaning |
|---|---|---|
| `connected` | `true`/`false` | Zapmizer's own state; `is_online` is its `is_online`. Other Zapmizer states (`disconnected`, `off`, `qrcode`, `booting`, ...) come through as they are. |
| `reauth_required` | `false` | The stored token was revoked on Zapmizer — the user has to connect again. |
| `instance_gone` | `false` | The instance was deleted on Zapmizer. |
| `zapmizer_unavailable` | `false` | Zapmizer is down, or answered something that is not JSON. |
| `null` | `false` | Nothing to query: no token or no instance stored. |

Without `live` no request leaves your server and `state`/`is_online` are absent. The route is throttled (60/min) because `live` reaches Zapmizer — poll it on user action or every minute, not every second.

### JSON error codes

The JSON endpoints (`show`, `start`, `destroy`) answer these through their exceptions:

| `code` | HTTP | Meaning |
|---|---|---|
| `zapmizer_unavailable` | 503 | Zapmizer is down or misbehaving — back off and retry. |
| `partner_unauthorized` | 503 | Your partner credentials were refused — configuration error, logged. |
| `no_connectable` | 403 | The resolver found nothing to connect on this request (no user, a user without a team). |

### Inertia + Vue components

A connect button, a connection panel and the composable that loads the state ship as publishable stubs, Jetstream-style:

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=zapmizer-wizard
```

This copies into `resources/js/`:

- `components/zapmizer/ConnectButton.vue` — opens the popup, listens to its `postMessage` (checking `event.origin` and `source === 'zapmizer-connect'`), shows a message per `status`, and has a "Já conectei" button for a popup that was closed by hand (emits `recheck`; `connected` on `ok`).
- `components/zapmizer/ConnectionPanel.vue` — the connected number, its live state (from `show?live=1`), reconnect and disconnect.
- `composables/useZapmizerIntegration.ts` — `reload({ live })`, `start()`, `disconnect()`; `openZapmizerConnect()` for the button.
- `types/zapmizer.ts` — `ZapmizerConnection`, `ZapmizerConnectStatus`, `ZapmizerConnectMessage`.

They expect `axios`, `lucide-vue-next` and Ziggy's `route()`; the markup uses utility classes from the app they came from (`gp-*`) — restyle them as yours. The result page of the popup is a Blade view you can publish with `--tag=views` (`resources/views/vendor/zapmizer/connect-result.blade.php`).

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

The webhook carries **no bytes**, and no URL to them either: the bot downloads the media in the background and the application fetches it from Zapmizer's API (`GET /api/whatsapp-messages/media`, with the connection's token). **The `message` webhook fires before that download is done** — asking right away usually gets a "still downloading", and the consumer has to come back. Zapmizer keeps trying for **600 seconds** from the message's `timestamp`; after that (or for a type it does not download, or a view-once message) the media is declared unavailable for good.

```php
$download = $connection->media($m);    // one request, no waiting

$download->state;         // 'attached' | 'downloading' | 'unavailable'
$download->isAttached();  // ->isDownloading() / ->isUnavailable()
$download->mimeType;      // Content-Type          (attached only)
$download->filename;      // from Content-Disposition — Zapmizer's `<id>.<ext>`, not the sender's name: use $m->mediaFilename() for that
$download->size;          // Content-Length

$download->path();        // temporary file with the bytes — deleted when $download is destroyed
$download->stream();      // fresh read handle: Storage::disk('s3')->put($key, $download->stream())
$download->contents();    // the whole file as a string — only if you need it in memory
```

`media()` streams the bytes into a temporary file; nothing is held in memory unless you call `contents()`. Move or copy the file (`Storage::put`, `putFileAs(new File($download->path()))`) before the object goes away. It needs the connection's `bot_instance_id` (a connection that lost its pairing throws `ZapmizerUnauthorizedException`, like `instanceClient()` without a token) and passes `$m->id` and `$m->sentAt` along — the timestamp is what lets Zapmizer answer `unavailable` instead of `downloading` forever.

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

For a command or a simple listener there is `awaitMedia()`, which blocks and retries for you:

```php
$download = $connection->awaitMedia($m);                          // sleeps 2, 5, 10, 20, 30 s between tries (67 s worst case)
$download = $connection->awaitMedia($m, waitsSeconds: [3, 3, 3]); // your own schedule
```

It returns the last answer: `attached`, `unavailable` as soon as Zapmizer says so, or `downloading` when the waits ran out — check `isDownloading()` and decide whether to give up. The `$sleep` closure argument replaces the real sleep in tests.

Requires **Zapmizer 1.150.0 or later** (media endpoint). <!-- TODO confirm the release that ships `api-media-mensagem` -->

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
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;        // resolver has nothing to connect (renders 403 no_connectable)
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;         // instance deleted (4xx on its connection endpoint)
```

The clients behind the controller (`Connect\PartnerClient`, `Connect\InstanceClient`) are container-bound with an injected Guzzle client, like `VerificationClient` — swap `GuzzleHttp\Client` in the container to fake them in tests. Every client sends `Accept: application/json` and does **not** follow redirects: a revoked token makes Zapmizer redirect to its login page, and a redirect or a non-JSON answer is an exception (`unexpectedResponse`) instead of a silent "success". `InstanceClient` always acts for one connection: its token is mandatory, there is no fallback to `zapmizer.api_token` — get it through `$connection->instanceClient()`. It only knows `connection($id)`, `createWebhook($url)`, `rotateWebhookSecret($id)`, `deleteWebhook($id)` and `media($botInstanceId, $messageId, $timestamp = null)` — instances are created and paired on Zapmizer's page, not from here. `media()` is the one endpoint whose 200 is not JSON (the bytes); its 202/404 answers are, and a redirect is still refused.

`PartnerClient::createSession($redirectUri, $state, $webhookUrl = null, $expiresIn = null)` never asks for an `expires_in` below Zapmizer's floor of **900 seconds** (`PartnerClient::MIN_EXPIRES_IN`): the same signature covers the page, the authorization and the QR code, and a shorter one died mid-pairing.

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

## Upgrading from 0.1.x

**Breaking** (0.x: a minor bump is the breaking bump). The pairing moved to Zapmizer's hosted page, and the wizard on your side went with it.

- **Requires Zapmizer 1.149.0 or later** (hosted pairing). Against an older Zapmizer the callback still lands with the token, but with no number: the connection is stored inactive and there is no longer a wizard to pair it.
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
