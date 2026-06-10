# WhatsApp number verification

This guide walks through wiring the Zapmizer number verification into a Laravel application, end to end: preparing the `User` model, starting a verification through the hosted page, and receiving the confirmation back (signed return + webhook).

## How it works

The default flow uses the **hosted verification page** (Stripe billing-portal style):

```
your app                          zapmizer                         user
   │  1. create session (sk_)        │                               │
   ├────────────────────────────────►│                               │
   │  ◄── hosted page url ───────────┤                               │
   │  2. redirect user ──────────────┼──────────────────────────────►│
   │                                 │  3. wa.me link + code happen  │
   │                                 │     on the hosted page        │
   │  4. signed return redirect ◄────┤                               │
   │  5. signed webhook ◄────────────┤  (server-to-server)           │
```

The user never leaves a Zapmizer-branded page to do the WhatsApp part; your app only starts the session and consumes the result. State is tracked locally in the package's `whatsapp_verifieds` table — 1:1 with your user, your `users` table is never touched.

## 1. Zapmizer-side setup

In your Zapmizer dashboard, open **Settings → Verifications** and create a verification. You'll need a bot instance with a connected number. From the verification screen, collect three credentials (they are all different things):

| Credential | Looks like | Used for |
|---|---|---|
| Secret key | `sk_...` | Creating hosted page sessions, server-side. Shown **once** when created. |
| Webhook secret | `whsec_...` | Validating the signed return redirect and the webhook. |
| Publishable key | `pk_...` | Only for the embedded-widget flow (optional, see below). |

Also configure on the verification:

- the **webhook URL**, pointing at your app's `https://your-app.com/zapmizer/webhook` (must be public https — Zapmizer won't deliver to local/private URLs);
- the **allowed origins**, only needed for the widget flow (`pk_` is public, the origin allowlist is what protects it).

## 2. Package setup

Publish the config and the migrations, then migrate:

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=config
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=migrations
php artisan migrate
```

This creates two tables: `whatsapp_verifieds` (verification state, 1:1 with the user) and `zapmizer_webhook_events` (audit/idempotency of received webhooks).

Set the environment variables:

```env
ZAPMIZER_SECRET_KEY=sk_...                  # hosted sessions (server-side)
ZAPMIZER_WEBHOOK_SECRET=whsec_...           # signed return + webhook validation
ZAPMIZER_RETURN_URL=https://your-app.com/whatsapp/verified

# optional
ZAPMIZER_BASE_URI=https://app.zapmizer.com/api/
ZAPMIZER_PUBLISHABLE_KEY=pk_...             # widget flow only
ZAPMIZER_ORIGIN=                            # widget flow only, defaults to app.url
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
| `startWhatsappVerification(?string $returnUrl = null)` | Creates a hosted session, records the state as `awaiting` and returns the hosted page URL to redirect the user to. |
| `markWhatsappAsVerified()` | Marks as verified (used by the webhook / your signed-return handler). |
| `whatsappVerification()` | `HasOne` relation to the state record (`status`, `url`, `number`, `verified_at`). |
| `getWhatsappNumberForVerification()` | Where the number to verify is read from. |
| `confirmWhatsappVerification($code)` / `syncWhatsappVerificationStatus()` | Widget-flow helpers (see below). |

By default the number is read from a `whatsapp_number` attribute. If yours lives somewhere else, override the getter:

```php
public function getWhatsappNumberForVerification(): ?string
{
    return $this->phone;
}
```

> **Note:** in the hosted flow the number is informative — the user types/confirms it on the hosted page, and the verified number comes back in the webhook payload. The model doesn't even need a number set to start a verification.

## 4. Starting a verification

The package auto-registers a named GET route, `zapmizer.verify_number` (at `/zapmizer/verify-number`, behind `web` + `auth`). It starts the verification for the authenticated user and redirects straight to the hosted page — so the frontend is just a link:

```blade
<a href="{{ route('zapmizer.verify_number') }}">Verify your WhatsApp</a>
```

Prefix and middleware are configurable, and the routes can be disabled entirely (`zapmizer.routes` config) if you'd rather mount your own:

```php
$url = $user->startWhatsappVerification();   // uses config('zapmizer.return_url')

return redirect()->away($url);
```

The verification is correlated by `client_reference = $user->getKey()`, sent automatically — that's how the return redirect and the webhook find their way back to the right user.

## 5. Handling the signed return

When the user completes the hosted page, Zapmizer redirects them to your `return_url` with signed query params:

```
GET /whatsapp/verified?verify_session=42&status=verified&sig=t=1781...,v1=ab12...
```

`verify_session` is the `client_reference` (the user id) and `sig` is an HMAC-SHA256 over the other params with your **webhook secret**. The signature is what authenticates this request — don't rely on the browser session being alive:

```php
use Illuminate\Support\Facades\Auth;
use NotificationChannels\Zapmizer\Support\ZapbotSignature;

Route::get('/whatsapp/verified', function (Request $request) {
    abort_unless(
        ZapbotSignature::isValidQuery($request->query(), config('zapmizer.webhook_secret')),
        403
    );

    if ($request->query('status') === 'verified') {
        $user = User::findOrFail($request->query('verify_session'));
        $user->markWhatsappAsVerified();
        Auth::login($user); // optional: restore the session after the round-trip
    }

    return redirect()->route('dashboard');
});
```

## 6. The confirmation webhook

The redirect above is a UX nicety; the **webhook is the source of truth** (the user can close the tab before being redirected). The package registers a public, stateless `POST /zapmizer/webhook` route that does everything for you:

- the `VerifyWebhookSignature` middleware rejects anything not signed with your webhook secret;
- deliveries are recorded in `zapmizer_webhook_events` — the unique `event_id` makes redeliveries (in any order) idempotent;
- the event is correlated by `client_reference`, never by phone number;
- a confirmation marks the user's number as verified and fires an event; failures/expirations move the state to `failed`.

React to the confirmation in your app:

```php
use NotificationChannels\Zapmizer\Events\WhatsappVerified;

Event::listen(function (WhatsappVerified $event) {
    $event->verification;        // the state record
    $event->verification->user;  // the verified user
});
```

`WebhookReceived` and `WebhookHandled` events (Cashier-style) also fire around the handling. To customize handling, extend `NotificationChannels\Zapmizer\Http\Controllers\WebhookController` — each event type maps to a `handle{StudlyType}` method (`verify_number.verified` → `handleVerifyNumberVerified`).

## 7. Checking the state

```php
$user->hasVerifiedWhatsapp();              // bool

$record = $user->whatsappVerification()->first();
$record->status;       // awaiting | verified | failed
$record->number;       // the verified number (from the webhook payload)
$record->url;          // hosted page link while awaiting
$record->verified_at;
```

Gate anything on it — middleware, policies, or a simple check before the action:

```php
abort_unless($user->hasVerifiedWhatsapp(), 403, 'Verify your WhatsApp first.');
```

## Alternative: embedded widget flow

If you embed Zapmizer's `verify.js` widget instead of using the hosted page, the package's `VerificationClient` also speaks that API (publishable key + origin allowlist): `create($number)` returns the wa.me link, `get($number)` polls the state, `confirm($number, $code)` submits the code the user received. On the model, `confirmWhatsappVerification($code)` and `syncWhatsappVerificationStatus()` wrap those against the state record.

## Error handling

Timeouts and API errors never bubble up as loose Guzzle exceptions:

```php
use NotificationChannels\Zapmizer\Exceptions\VerificationConnectionFailed; // network/timeout
use NotificationChannels\Zapmizer\Exceptions\VerificationRequestFailed;    // 4xx/5xx (getStatusCode(), getResponseBody())
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException; // base of all of the above
```

## Local development notes

- Zapmizer only delivers webhooks to **public https URLs**. Locally, rely on the signed return redirect, or tunnel the webhook route (e.g. `ngrok http 8000` and point the verification's webhook URL at `https://<tunnel>/zapmizer/webhook`).
- The signed return works fine on localhost — it goes through the user's browser.

## Customization summary

| Config key | Purpose |
|---|---|
| `zapmizer.secret_key` | `sk_` for hosted sessions |
| `zapmizer.webhook_secret` | validates signed return + webhook |
| `zapmizer.return_url` | default return URL for hosted sessions |
| `zapmizer.publishable_key` / `zapmizer.origin` | widget flow |
| `zapmizer.routes.*` | enable/prefix/middleware of the package routes |
| `zapmizer.models.whatsapp_verified` | subclass the state model |
| `zapmizer.models.webhook_event` | subclass the webhook audit model |
