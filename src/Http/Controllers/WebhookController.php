<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Models\WebhookEvent;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class WebhookController.
 *
 * Receives Zapmizer's webhooks, Cashier-style: each event name is routed to
 * a `handle{StudlyName}` method — extend the controller and add/override
 * handlers to customize behavior. Payloads follow the team-webhook shape:
 * `{ "name": "verify_number.verified", "data": { "number": ..., "from": ... } }`.
 *
 * Deliveries are NOT signed by Zapmizer — they arrive on the team's
 * registered webhooks like any bot notification. Protect the route through
 * `zapmizer.routes.webhook_middleware` (throttle, IP allowlist, a shared
 * token segment in the URL, ...) and keep the handlers idempotent, which
 * they are by default: marking verified twice is a no-op and a `failed`
 * event never downgrades an already-verified number.
 *
 * Every delivery is recorded in the zapmizer_webhook_events table as an
 * audit trail. Correlation uses the (canonical) phone number from the
 * payload, matched against the state records with the Brazilian extra-9
 * tolerance.
 */
class WebhookController extends Controller
{
    /**
     * The webhook event record for the delivery being handled.
     */
    protected ?WebhookEvent $event = null;

    /**
     * Handle a Zapmizer webhook call.
     */
    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        abort_unless(is_array($payload), 400, 'Malformed webhook payload.');

        WebhookReceived::dispatch($payload);

        $this->event = $this->webhookEventModel()::query()->create([
            'name' => $payload['name'] ?? null,
            'number' => $payload['data']['number'] ?? null,
            'payload' => $payload,
        ]);

        $method = 'handle' . Str::studly(str_replace('.', '_', (string) ($payload['name'] ?? '')));

        if (method_exists($this, $method)) {
            $response = $this->{$method}($payload);

            WebhookHandled::dispatch($payload);

            return $response;
        }

        return $this->missingMethod($payload);
    }

    /**
     * Handle a confirmed verification.
     */
    protected function handleVerifyNumberVerified(array $payload): Response
    {
        $record = $this->findVerification($payload);

        if ($record === null) {
            return $this->missingMethod($payload);
        }

        $record->forceFill([
            'number' => $payload['data']['number'] ?? $record->number,
            'status' => WhatsappVerified::STATUS_VERIFIED,
            'verified_at' => $record->verified_at ?? $record->freshTimestamp(),
        ])->save();

        event(new WhatsappVerifiedEvent($record));

        return $this->successMethod();
    }

    /**
     * Handle a failed verification (attempts exhausted).
     */
    protected function handleVerifyNumberFailed(array $payload): Response
    {
        $record = $this->findVerification($payload);

        // Never downgrade an already-verified number — a late or redelivered
        // failed event must not undo a confirmed verification.
        if ($record === null || $record->isVerified()) {
            return $this->missingMethod($payload);
        }

        $record->forceFill([
            'status' => WhatsappVerified::STATUS_FAILED,
            'verified_at' => null,
        ])->save();

        return $this->successMethod();
    }

    /**
     * Find the verification the payload refers to, by phone number.
     */
    protected function findVerification(array $payload): ?WhatsappVerified
    {
        $candidates = $this->numberCandidates((string) ($payload['data']['number'] ?? ''));

        if ($candidates === []) {
            return null;
        }

        return $this->verificationModel()::query()
            ->whereIn('number', $candidates)
            ->latest('id')
            ->first();
    }

    /**
     * Lookup candidates for a number: digits, +-prefixed, and the Brazilian
     * with/without-extra-9 variants — Zapmizer reports the canonical number,
     * which may differ from how the application stored it.
     *
     * @return array<int, string>
     */
    protected function numberCandidates(string $number): array
    {
        $digits = preg_replace('/\D/', '', $number);

        if ($digits === '') {
            return [];
        }

        $candidates = [$digits];

        if (str_starts_with($digits, '55') && strlen($digits) === 13 && $digits[4] === '9') {
            $candidates[] = substr($digits, 0, 4) . substr($digits, 5); // drop the extra 9
        }

        if (str_starts_with($digits, '55') && strlen($digits) === 12) {
            $candidates[] = substr($digits, 0, 4) . '9' . substr($digits, 4); // add the extra 9
        }

        foreach ($candidates as $candidate) {
            $candidates[] = '+' . $candidate;
        }

        return $candidates;
    }

    /**
     * Handle successful calls on the controller.
     *
     * @param array $parameters
     */
    protected function successMethod($parameters = []): Response
    {
        $this->event?->forceFill(['handled' => true])->save();

        return new Response('Webhook Handled', 200);
    }

    /**
     * Handle calls to missing methods on the controller.
     *
     * @param array $parameters
     */
    protected function missingMethod($parameters = []): Response
    {
        return new Response('Webhook Received', 200);
    }

    /**
     * @return class-string<WhatsappVerified>
     */
    protected function verificationModel(): string
    {
        return config('zapmizer.models.whatsapp_verified', WhatsappVerified::class);
    }

    /**
     * @return class-string<WebhookEvent>
     */
    protected function webhookEventModel(): string
    {
        return config('zapmizer.models.webhook_event', WebhookEvent::class);
    }
}
