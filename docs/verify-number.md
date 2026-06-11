# WhatsApp number verification

This guide walks through wiring the Zapmizer number verification into a Laravel application, end to end: preparing the `User` model, starting a verification through the hosted page, confirming the code and receiving the terminal state via webhook.

## How it works

```
your app ──(API token)──> POST /api/verify-number/sessions ──> { url }   [signed, temporary]
your app redirects the user ──> hosted page on the Zapmizer domain
user ──wa.me──> sends the trigger phrase to the team's WhatsApp number
bot replies with a 6-digit code in the chat
user types the code ──> on the hosted page, or in your app (confirm endpoint)
terminal state (verified|failed) ──> webhook ──> the team's registered webhooks
```

The end user proves ownership by messaging the team's WhatsApp number and typing back the code the bot replies with. Your app only starts the session and consumes the result. State is tracked locally in the package's `whatsapp_verifieds` table — 1:1 with your user, your `users` table is never touched.

## 1. Zapmizer-side setup

You need:

- a **bot instance online** (a connected WhatsApp number) — it receives the trigger message and replies with the code;
- your team's **API token** (the same Sanctum token used by the messages API);
- optionally, a **webhook** registered in the app's webhooks resource pointing at your application (see [§6](#6-the-webhook)).

There are no verification-specific credentials: the API token authenticates everything, and the tenant is resolved from it.

## 2. Package setup

Publish the config and the migrations, then migrate:

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=config
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=migrations
php artisan migrate
```

This creates the `whatsapp_verifieds` table (verification state, 1:1 with the user).

Set the environment variables:

```env
ZAPMIZER_API_TOKEN=...                       # the team's API token
ZAPMIZER_BASE_URI=https://app.zapmizer.com/api/
ZAPMIZER_FROM_NUMBER=                        # optional: which team number receives the
                                             # verification (default: first online bot)
ZAPMIZER_RETURN_URL=                         # optional: "back to the site" button on the
                                             # hosted page (e.g. https://your-app.com/account)
```

## 3. Preparing the User model

Mirroring Laravel's `MustVerifyEmail`: implement the contract and use the trait of the same name.

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp as MustVerifyWhatsappContract;
use NotificationChannels\Zapmizer\MustVerifyWhatsapp;

class User extends Authenticatable implements MustVerifyWhatsappContract
{
    use MustVerifyWhatsapp;
}
```

That's the whole model setup. The trait gives you:

| Method | What it does |
|---|---|
| `hasVerifiedWhatsapp()` | Whether the number is verified. |
| `startWhatsappVerification()` | Creates a hosted session, records the state as `awaiting` and returns the hosted page URL to redirect the user to. |
| `confirmWhatsappVerification($code)` | Confirms the code server-side (when your app owns the code input). |
| `markWhatsappAsVerified()` | Marks as verified (used by the webhook handler, or wherever you trust). |
| `whatsappVerification()` | `HasOne` relation to the state record (`status`, `url`, `number`, `verified_at`). |
| `getWhatsappNumberForVerification()` | Where the number to verify is read from. |

By default the number is read from a `whatsapp_number` attribute. If yours lives somewhere else, override the getter:

```php
public function getWhatsappNumberForVerification(): ?string
{
    return $this->phone;
}
```

> **Note:** the model's number is sent along when creating the session and **prefills the input on the hosted page** — the user just confirms (or corrects) it there. The canonical verified number comes back in the confirm response / webhook payload. The model doesn't need a number set to start a verification.

## 4. Starting a verification

The package auto-registers a named GET route, `zapmizer.verify_number` (at `/zapmizer/verify-number`, behind `web` + `auth`). It starts the verification for the authenticated user and redirects straight to the hosted page — so the frontend is just a link:

```blade
<a href="{{ route('zapmizer.verify_number') }}">Verify your WhatsApp</a>
```

Prefix and middleware are configurable, and the routes can be disabled entirely (`zapmizer.routes` config) if you'd rather mount your own:

```php
$url = $user->startWhatsappVerification();

return redirect()->away($url);
```

The hosted page URL is signed and temporary (default 1 hour; Zapmizer caps it at 24h). On the page, the user opens the wa.me link, sends the trigger phrase, receives the code on WhatsApp and can type it right there — nothing else for your app to do besides checking the state afterwards.

When a return URL is set (`zapmizer.return_url`, or per call: `$user->startWhatsappVerification($returnUrl)`), the hosted page shows a "back to the site" button pointing at it. It rides the signed URL, so it's tamper-proof — but the redirect is plain navigation and does **not** prove the verification outcome; rely on the webhook or the confirm call for that.

## 5. Confirming the code in your app (optional)

If you'd rather own the code-input UX (the user receives the code on WhatsApp and types it into *your* screen), use the server-side confirm path:

```php
if ($user->confirmWhatsappVerification($request->input('code'))) {
    // verified — the canonical number was stored on the state record
}
```

For finer-grained outcomes, call the client directly:

```php
use NotificationChannels\Zapmizer\VerificationClient;

$result = app(VerificationClient::class)->confirm($number, $code);

$result->status;       // verified | invalid | failed | not_found
$result->number;       // canonical WhatsApp number (when verified)
$result->from;         // the team number that received the message
$result->attemptsLeft; // when invalid (5 attempts by default)
```

`not_found` also covers the short window while the code is still propagating after the user sent the message — treat it as "retry in a few seconds", not a hard error. Codes are single-use and expire in 5 minutes.

## 6. The webhook

Terminal states (`verified` / `failed`) are also delivered to the **team's registered webhooks** (the same webhooks resource bot notifications use — register your URL there once). The package's public `POST /zapmizer/webhook` route handles them Cashier-style:

- each event name maps to a `handle{StudlyName}` method (`verify_number.verified` → `handleVerifyNumberVerified`) — extend the controller to customize;
- the event is correlated to the state record by the **canonical phone number** (tolerant to the Brazilian extra-9);
- effects are idempotent: redeliveries are no-ops and a late `failed` never downgrades an already-verified number;
- a confirmation fires `NotificationChannels\Zapmizer\Events\WhatsappVerified`; `WebhookReceived`/`WebhookHandled` fire around the handling;
- like Cashier, the deliveries themselves are not persisted — listen to `WebhookReceived` if you want to log them.

```php
use NotificationChannels\Zapmizer\Events\WhatsappVerified;

Event::listen(function (WhatsappVerified $event) {
    $event->verification;        // the state record
    $event->verification->user;  // the verified user
});
```

> **Security note:** Zapmizer does **not** sign webhook deliveries — they arrive like any team-webhook notification (`User-Agent: Zapmizer`). The handlers only ever upgrade state idempotently, but if you want to harden the endpoint, add a throttle/IP allowlist via `zapmizer.routes.webhook_middleware`, or disable the package routes and mount the controller behind your own protection (e.g. an unguessable URL prefix).

Payload shapes, for reference:

```json
{ "name": "verify_number.verified", "data": { "number": "5511912345678", "from": "5511333334444" } }
{ "name": "verify_number.failed",   "data": { "number": "...", "from": "...", "reason": "too_many_attempts" } }
```

Only `verified` and `failed` fire — expiry is passive (the code just stops existing), so there is no `expired` webhook.

## 7. Checking the state

```php
$user->hasVerifiedWhatsapp();              // bool

$record = $user->whatsappVerification()->first();
$record->status;       // awaiting | verified | failed
$record->number;       // canonical number once verified
$record->url;          // hosted page link while awaiting (signed, temporary)
$record->verified_at;
```

Gate anything on it — middleware, policies, or a simple check before the action:

```php
abort_unless($user->hasVerifiedWhatsapp(), 403, 'Verify your WhatsApp first.');
```

## Error handling

Timeouts and API errors never bubble up as loose Guzzle exceptions:

```php
use NotificationChannels\Zapmizer\Exceptions\VerificationConnectionFailed; // network/timeout
use NotificationChannels\Zapmizer\Exceptions\VerificationRequestFailed;    // 4xx/5xx (getStatusCode(), getResponseBody())
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException; // base of all of the above
```

Notable API errors when creating a session: `503` when the team has no online bot, `422` when the `from` number doesn't match any of the team's WhatsApp accounts.

## Local development notes

- Outside production, Zapmizer's anti-SSRF guard is off — webhooks **are** delivered to local URLs, so registering `http://localhost:8000/zapmizer/webhook` in the team's webhooks works against a local Zapmizer.
- Without the webhook, the in-app confirm path (`confirmWhatsappVerification`) closes the loop on its own.

## Customization summary

| Config key | Purpose |
|---|---|
| `zapmizer.api_token` | the team's API token (shared with the messages API) |
| `zapmizer.from_number` | which team number receives the verification (optional) |
| `zapmizer.return_url` | "back to the site" button on the hosted page (optional) |
| `zapmizer.routes.*` | enable/prefix/middleware of the package routes |
| `zapmizer.models.whatsapp_verified` | subclass the state model |
