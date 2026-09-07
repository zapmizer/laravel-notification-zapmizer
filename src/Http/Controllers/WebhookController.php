<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyWebhookSignature;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Support\PhoneNumber;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class WebhookController.
 *
 * Receives Zapmizer's webhooks, Cashier-style: each event name is routed to
 * a `handle{StudlyName}` method — extend the controller and add/override
 * handlers to customize behavior. Payloads follow the team-webhook shape:
 * `{ "name": "verify_number.verified", "data": { "number": ..., "from": ... } }`.
 *
 * Like Cashier, nothing about the delivery itself is persisted — listen to
 * the WebhookReceived event if you want to log them. Handlers are
 * idempotent by design: marking verified twice is a no-op and a `failed`
 * event never downgrades an already-verified number, so redeliveries are
 * harmless.
 *
 * Bot events (`message`, ...) are signed by Zapmizer (`X-Zapmizer-Signature`,
 * HMAC-SHA256 of `"{timestamp}.{raw body}"`). The VerifyWebhookSignature
 * middleware — applied to the route by the package — checks it against the
 * application secret (`zapmizer.webhook.secret`) and every
 * ZapmizerConnection, and hands the matching connection (null for the
 * application secret) to the handlers. `verify_number.*` events are the
 * only ones Zapmizer delivers unsigned, and the only ones accepted so;
 * their correlation uses the (canonical) phone number from the payload,
 * matched against the state records with the Brazilian extra-9 tolerance.
 */
class WebhookController extends Controller
{
    /**
     * The delivery being handled.
     */
    protected ?Request $request = null;

    /**
     * Handle a Zapmizer webhook call.
     */
    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        abort_unless(is_array($payload), 400, 'Malformed webhook payload.');

        $this->request = $request;

        WebhookReceived::dispatch($payload, $this->connection());

        $method = 'handle' . Str::studly(str_replace('.', '_', (string) ($payload['name'] ?? '')));

        if (method_exists($this, $method)) {
            $response = $this->{$method}($payload);

            WebhookHandled::dispatch($payload, $this->connection());

            return $response;
        }

        return $this->missingMethod($payload);
    }

    /**
     * Handle an inbound WhatsApp message.
     *
     * Translates the whatsapp-web.js Message into an InboundMessage and fires
     * MessageReceived with the connection that received it (null when the
     * delivery was signed with the application's single-tenant secret).
     * Nothing is persisted — the application listens and decides
     * (Cashier-style). Payloads we can't make sense of are acknowledged and
     * not handled.
     */
    protected function handleMessage(array $payload): Response
    {
        $message = InboundMessage::fromEnvelope($payload, $this->request?->header('X-Wid'));

        if ($message === null) {
            return $this->missingMethod($payload);
        }

        MessageReceived::dispatch($message, $this->connection(), $payload);

        return $this->successMethod();
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
        if (PhoneNumber::digits($number) === '') {
            return [];
        }

        $candidates = PhoneNumber::variants($number);

        foreach ($candidates as $candidate) {
            $candidates[] = '+' . $candidate;
        }

        return $candidates;
    }

    /**
     * The connection whose secret signed the current delivery. Null for a
     * delivery signed with `zapmizer.webhook.secret` (single-tenant) and for
     * the unsigned `verify_number.*` events.
     */
    protected function connection(): ?ZapmizerConnection
    {
        $connection = $this->request?->attributes->get(VerifyWebhookSignature::CONNECTION_ATTRIBUTE);

        return $connection instanceof ZapmizerConnection ? $connection : null;
    }

    /**
     * Handle successful calls on the controller.
     *
     * @param array $parameters
     */
    protected function successMethod($parameters = []): Response
    {
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
}
