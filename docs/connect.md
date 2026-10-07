# Connecting a WhatsApp number per model (multi-tenant)

This guide wires the Zapmizer **connect flow** into a Laravel application: each model of yours (a `Team`, a `User`, ...) authorizes its own Zapmizer account **and pairs a WhatsApp number** in a single hosted popup, and from then on sends from — and receives on — that number. It mirrors Laravel Cashier: a `Connectable` trait on the model, a package-owned table, a controller with stable response codes, and events your app listens to.

The single-tenant setup (`ZAPMIZER_API_TOKEN` + `ZAPMIZER_FROM_NUMBER`) keeps working — `Connectable` is an addition, not a replacement.

## How it works

```
your app ──(X-Partner-Key)──> POST /api/connect/sessions { redirect_uri, state, webhook_url } ──> { url }   [popup]
user authorizes a team AND pairs a number (QR code) on the hosted page
        ──> redirect to your callback with ?code&state   (or ?error=access_denied|plan_limit|qr_unavailable)
your app ──(X-Partner-Key)──> POST /api/connect/token { code }
        ──> { token, user_id, team_id, team_name, phone_number, bot_instance_id, webhook_id, webhook_secret }
everything stored (token and secret encrypted), connection ACTIVE
Zapmizer ──(signed)──> POST /zapmizer/webhook ──> MessageReceived event
```

There is no wizard on your side: no instance creation, no QR code rendering, no polling. The popup opens, the user comes back, the connection is ready. Your screen needs one button and one panel.

## 1. Zapmizer-side setup

You need **partner credentials** (id + secret), issued by Zapmizer for your application. They authenticate the hosted connect flow; each connected team's own token is obtained through it and stored by the package.

**This flow needs a Zapmizer with the hosted pairing** — `POST /api/connect/sessions` accepting `webhook_url` and the token exchange answering `phone_number`/`bot_instance_id`/`webhook_id`/`webhook_secret`. That is Zapmizer **1.149.0 or later** (the release after `connect-pareamento`). The token exchange must also answer `user_id` and `team_id`, which the contract requires: without them the callback reports `exchange_failed` and stores nothing.

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
| `zapmizer.connect.start` | POST | `{ url, expires_at }` — open `url` in a popup (520×720 works). `expires_at` is ISO 8601 with the offset (`2026-09-07T01:00:00+00:00`), or `null`. Stores the `state` in the session and sends `route('zapmizer.webhook')` as the session's `webhook_url`. |
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
| `instance_gone` | `false` | The instance was deleted on Zapmizer (404). |
| `zapmizer_unavailable` | `false` | Zapmizer is down, refused the call with another 4xx (403, 422, 429, ...), or answered something that is not JSON. |
| `null` | `false` | Nothing to query: no token or no instance stored. |

Without `live` no request leaves your server and `state`/`is_online` are absent. The route is throttled (60/min) because `live` reaches Zapmizer — poll it on user action or every minute, not every second.

### JSON error codes

The JSON endpoints (`show`, `start`, `destroy`) answer these themselves, with or without `Accept: application/json` (the package's exceptions do not render):

| `code` | HTTP | Meaning |
|---|---|---|
| `zapmizer_unavailable` | 503 | Zapmizer is down, misbehaving or refused the session (404, 409, 422, 429) — back off and retry. Reported. |
| `partner_unauthorized` | 503 | Your partner credentials were refused — configuration error, logged and reported. |
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

The webhook carries **no bytes**, and no URL to them either: the bot downloads the media in the background and the application fetches it from Zapmizer's API (`GET /api/whatsapp-messages/media`, with the connection's token). **The `message` webhook fires before that download is done** — asking right away usually gets a "still downloading", and the consumer has to come back. Zapmizer keeps trying for **600 seconds counted from the message's `timestamp`** (a required parameter of the endpoint, and it must not be in the future); after that (or for a type it does not download, or a view-once message) the media is declared unavailable for good.

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

`media()` streams the bytes into a temporary file; nothing is held in memory unless you call `contents()`. Move or copy the file (`Storage::put`, `putFileAs(new File($download->path()))`) before the object goes away. It needs the connection's `bot_instance_id` (a connection that lost its pairing throws `ZapmizerConnectException::notPaired()`; `instanceClient()` without a token throws `ZapmizerUnauthorizedException`) and passes `$m->id` and `$m->sentAt` along — the timestamp is required over there: the 600 s window is counted from it, and it is what lets Zapmizer answer `unavailable` instead of `downloading` forever.

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

For a command or a simple listener there is `awaitMedia()`, which blocks and retries for you:

```php
$download = $connection->awaitMedia($m);                          // sleeps 2, 5, 10, 20, 30 s between tries (67 s worst case)
$download = $connection->awaitMedia($m, waitsSeconds: [3, 3, 3]); // your own schedule
```

It returns the last answer: `attached`, `unavailable` as soon as Zapmizer says so, or `downloading` when the waits ran out — check `isDownloading()` and decide whether to give up. A **429** on the way is treated like a `downloading`, not thrown: it waits Zapmizer's `Retry-After` or the next entry of the schedule, whichever is longer, and asks again (still rate-limited when the schedule runs out → `downloading`). A **422** propagates as `MediaRejectedException`. The `$sleep` closure argument replaces the real sleep in tests.

Requires **Zapmizer 1.150.0 or later** (media endpoint). <!-- TODO confirm the release that ships `api-media-mensagem` -->

## 7. Rotating the webhook secret

```php
$team->zapmizerConnection->rotateWebhookSecret();
```

Calls `POST /api/webhooks/{id}/secret` and stores both the new secret and the previous one — Zapmizer signs with both until the next rotation, so deliveries in flight keep validating.

That also means **rotating once does not revoke a leaked secret**: the previous one keeps signing (and validating) until the next rotation. To retire a compromised secret, rotate **twice**.

## 8. Subscription and checkout

`PartnerClient` also reads a customer's subscription and creates its checkout. The customer is identified by the `external_id` you sent with the connect session: it exists on Zapmizer once the customer approves that connect, and before that both calls answer 404. The package's own connect route (`zapmizer.connect.start`) does not send an `external_id` yet; call `createSession()` yourself to send one.

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

## 9. Reconnect, revoke and embed

These calls act for one connection, with its token: get the client through `$connection->instanceClient()`. None of them is called by the package's routes; your app decides when.

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

- `revokeToken(): void` — `DELETE /api/connect/token` revokes the token the client authenticates with. Use it when the customer removes the integration, and to revoke the previous token after a new connect replaced it (the client must be built for the previous token, as above: `instanceClient()` uses whichever `api_token` is stored when it is called, so calling it after the swap revokes the new token and leaves the old one valid). In this release the package's own connect callback (`zapmizer.connect.callback`) overwrites `api_token` without revoking the previous one, so revoking it is up to your app. Zapmizer also points to it to recover a lost webhook secret.
- It returns on 204 (revoked now) and on 401 (the token was already revoked, or is not valid). A 404 is a `ZapmizerApiException` with `status()` 404: the token did not come from a connect and **is still valid**. A 403 (e-mail not verified) also means nothing was revoked. 5xx or no answer is a `ZapmizerUnavailableException`, a 429 a `ZapmizerRateLimitedException`, and a redirect or another 2xx `unexpectedResponse`.
- 401 and 404 also come from a wrong `base_uri` or environment (an unknown route answers 404). Never let a failed revocation block the removal: catch it, report it and go on.

### Embedding a conversation or the inbox

```php
$client = $connection->instanceClient();

$conversation = $client->conversationSession(
    phone: '5521988887777',
    parentOrigin: 'https://app.example.com',
    appearance: ['theme' => 'dark', 'color_primary' => '#2E6BFF'],
    userId: (string) $user->id,
    userName: $user->name,
);

$inbox = $client->inboxSession('https://app.example.com', (string) $user->id, $user->name);

return response()->json([
    'url' => $inbox->url,
    'origin' => $inbox->origin,
    'resume_url' => $inbox->resumeUrl,
    'resume_until' => $inbox->resumeUntil?->toIso8601String(),
]);
```

- `conversationSession(string $phone, string $parentOrigin, array $appearance = [], ?string $userId = null, ?string $userName = null): EmbedSession` and `inboxSession(string $parentOrigin, ?string $userId = null, ?string $userName = null): EmbedSession` — `POST /api/embed/sessions` with `component` `conversation` or `inbox`. `appearance` goes only with the conversation, without its `null` values and only when something is left; the package does not validate it (Zapmizer answers 422). `user` carries the id and the name that are not `null` or blank, and is left out when neither is. `phone` and `parent_origin` go as given.
- `EmbedSession` carries `url` (open it in the iframe within 60 seconds; it opens once, never cache it), `origin` (the iframe's origin as the browser serializes it: lowercase, without the default port; compare `event.origin` with it), `expiresAt`, and, for the inbox, `resumeUrl` and `resumeUntil` (keep the `resumeUrl` per person and load it until `resumeUntil`). Dates are `?CarbonImmutable`, read like the others.
- The `url` must be safe for the iframe: `http` or `https`, no user or password, no whitespace, control character or backslash, a port from 1 to 65535, and an ASCII host (an IDN only in punycode) or a bracketed IPv6. Anything else is `unexpectedResponse`. A `resume_url` that is not safe, or of another origin than `url`, becomes `null` and logs a warning `zapmizer: unexpected resume url.` with `resume_origin` and `url_origin` only, never the URL.
- Refusals are `ZapmizerApiException` with `status()`, `error()` and `errors()`: 402 `subscription_required` (checkout), 403 `not_a_partner_connection`, `origin_not_allowed` or `missing_ability` (the inbox needs a connect made after its ability existed: connect again), 422 `connection_without_number`, `number_unavailable`, `approver_without_access`, or the field errors. 401 is a `ZapmizerUnauthorizedException`, 429 a `ZapmizerRateLimitedException`, 5xx a `ZapmizerUnavailableException`, and any answer other than a 201 with a safe `url` is `unexpectedResponse`.

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
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;         // instance deleted or not this connection's (404 on connection() and reconnect())
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;        // media request refused (422) — ->reason()
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;     // media endpoint rate limit (429) — ->retryAfter()
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;  // timeout / 5xx / no answer
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;        // resolver has nothing to connect
```

None of them renders a response: the routes above answer their JSON codes themselves, and anywhere else your exception handler decides. "Errors" in the README shows how to read a `ZapmizerApiException` and the `ErrorCode` constants.

The clients behind the controller (`Connect\PartnerClient`, `Connect\InstanceClient`) are container-bound with the configured transport — see "HTTP transport" in the README. With the default `GuzzleTransport`, swap `GuzzleHttp\Client` in the container to fake them in tests, like `VerificationClient`; with `LaravelHttpTransport`, use `Http::fake()`. Every client sends `Accept: application/json` and does **not** follow redirects: a revoked token makes Zapmizer redirect to its login page, and a redirect or a non-JSON answer is an exception (`unexpectedResponse`) instead of a silent "success". `InstanceClient` always acts for one connection: its token is mandatory, there is no fallback to `zapmizer.api_token` — get it through `$connection->instanceClient()`. It knows `connection($id)`, `createWebhook($url)`, `rotateWebhookSecret($id)`, `deleteWebhook($id)`, `media($botInstanceId, $messageId, $timestamp)`, `reconnect()`, `revokeToken()`, `conversationSession()` and `inboxSession()` (section 9) — instances are created and paired on Zapmizer's page, not from here. `media()` is the one endpoint whose 200 is not JSON (the bytes); its 202/404 answers are, and a redirect is still refused; its 422 (bad request, Meta Cloud instance) is a `MediaRejectedException` and its 429 (rate limit) a `MediaRateLimitedException`. On `InstanceClient`, any other 4xx is a `ZapmizerApiException` (401 `ZapmizerUnauthorizedException`, 429 `ZapmizerRateLimitedException`), except the 404 of `connection()` and `reconnect()` (`InstanceGoneException`), of `deleteWebhook()` (done) and of `media()` (`unavailable`), the 409 `needs_reconnect` of `reconnect()` (a result) and the 401 of `revokeToken()` (done). On `PartnerClient`, a refused partner credential (401/403) is a `PartnerCredentialsException`, `exchangeCode()` and `subscription()` return `null` on 404 (unknown, expired or already used code; no approved connect with that `external_id`), and any other 4xx is a `ZapmizerApiException` (429 `ZapmizerRateLimitedException`). A 4xx of a partner call is logged as `zapmizer: partner call failed.`, except 404 and 409: those are business answers (no connect yet, already subscribed, payment pending).

`PartnerClient::createSession($redirectUri, $state = null, $webhookUrl = null, $expiresIn = null, $externalId = null)` keeps `expires_in` between Zapmizer's floor of **900 seconds** (`PartnerClient::MIN_EXPIRES_IN`) and its ceiling of **86400** (`PartnerClient::MAX_EXPIRES_IN`): the same signature covers the page, the authorization and the QR code, and a shorter one died mid-pairing. `ConnectToken` carries `userId` and `teamId` (both `int`) and `hasNumber()`: `false` when `phone_number` or `bot_instance_id` came `null` (the number ceased to exist between the approval and the exchange; Zapmizer says to start a new session).

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

## Upgrading from 0.3.x

The partner client changed. The package's routes keep their behaviour, except the `start` JSON and a token answer without ids (below).

- `PartnerClient::createSession($redirectUri, $state = null, $webhookUrl = null, $expiresIn = null, $externalId = null)`: `state` became optional and `externalId` was added at the end. Named and positional calls keep working; a subclass that overrides `createSession()` must update its signature.
- `createSession()`, `subscription()` and `checkout()` throw `InvalidArgumentException` for an invalid `external_id` or `state` (`""`, `"0"` or whitespace at either end) before calling Zapmizer.
- `createSession()` caps `expires_in` at 86400, and sends an empty `redirect_uri`/`webhook_url` (Zapmizer answers 422) instead of dropping it.
- `ConnectSession::$expiresAt` is a `?CarbonImmutable` instead of a `?string`. The `start` JSON normalizes it: `2026-09-07T01:00:00Z` becomes `2026-09-07T01:00:00+00:00`, without fractions of a second.
- `ConnectToken`: `teamId` is an `int` (never `null`), `userId` was added as the second constructor argument, and an answer without a valid `user_id`/`team_id` throws `unexpectedResponse`. The callback then reports `exchange_failed` and stores nothing (it used to store the connection with a `null` `zapmizer_team_id`).
- A partner call answered 404 or 409 no longer logs `zapmizer: partner call failed.`.

## Upgrading from 0.1.x

**Breaking** (0.x: a minor bump is the breaking bump). The pairing moved to Zapmizer's hosted page, and the wizard on your side went with it.

- **Requires Zapmizer 1.149.0 or later** (hosted pairing). Against an older Zapmizer the callback still lands with the token, but with no number: the connection is stored inactive and there is no longer a wizard to pair it. With this release, a token exchange that does not answer `user_id`/`team_id` (Zapmizer before 1.148.8) makes the callback report `exchange_failed` and store nothing.
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
