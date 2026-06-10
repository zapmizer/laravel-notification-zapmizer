<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyWebhookSignature;
use NotificationChannels\Zapmizer\Models\WebhookEvent;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class WebhookController.
 *
 * Receives Zapbot's verify-number webhooks, Cashier-style: signature
 * verification lives in the VerifyWebhookSignature middleware, and each
 * event type is routed to a `handle{StudlyType}` method — extend the
 * controller and add/override handlers to customize behavior.
 *
 * Every verified delivery is recorded in the zapmizer_webhook_events table,
 * whose unique event_id is the idempotency key: redeliveries (in any order)
 * are acknowledged without reapplying the effect. Correlation uses the
 * `client_reference` the package sent when starting the verification (the
 * owner model's key) — never the phone number.
 */
class WebhookController extends Controller
{
    /**
     * The webhook event record for the delivery being handled.
     */
    protected ?WebhookEvent $event = null;

    /**
     * Create a new WebhookController instance.
     */
    public function __construct()
    {
        if (config('zapmizer.webhook_secret')) {
            $this->middleware(VerifyWebhookSignature::class);
        }
    }

    /**
     * Handle a Zapbot webhook call.
     */
    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        abort_unless(is_array($payload), 400, 'Malformed webhook payload.');

        WebhookReceived::dispatch($payload);

        $eventId = (string) ($payload['event_id'] ?? '');

        if (blank($eventId)) {
            return $this->missingMethod($payload);
        }

        $this->event = $this->webhookEventModel()::query()->firstOrCreate(
            ['event_id' => $eventId],
            [
                'type' => $payload['type'] ?? null,
                'status' => $payload['status'] ?? null,
                'client_reference' => isset($payload['client_reference']) ? (string) $payload['client_reference'] : null,
                'payload' => $payload,
            ],
        );

        // Idempotency: a redelivery of an already-recorded event is just acknowledged.
        if (!$this->event->wasRecentlyCreated) {
            return new Response('Webhook Duplicate', 200);
        }

        $method = 'handle' . Str::studly(str_replace('.', '_', (string) ($payload['type'] ?? '')));

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
            'number' => $payload['number'] ?? $record->number,
            'status' => WhatsappVerified::STATUS_VERIFIED,
            'verified_at' => $payload['verified_at'] ?? $record->freshTimestamp(),
        ])->save();

        event(new WhatsappVerifiedEvent($record));

        return $this->successMethod();
    }

    /**
     * Handle an expired verification.
     */
    protected function handleVerifyNumberExpired(array $payload): Response
    {
        return $this->markAsFailed($payload);
    }

    /**
     * Handle a failed verification.
     */
    protected function handleVerifyNumberFailed(array $payload): Response
    {
        return $this->markAsFailed($payload);
    }

    /**
     * Move the matching verification to the failed state.
     */
    protected function markAsFailed(array $payload): Response
    {
        $record = $this->findVerification($payload);

        if ($record === null) {
            return $this->missingMethod($payload);
        }

        $record->forceFill([
            'status' => WhatsappVerified::STATUS_FAILED,
            'verified_at' => null,
        ])->save();

        return $this->successMethod();
    }

    /**
     * Find the verification the payload refers to, by client_reference.
     */
    protected function findVerification(array $payload): ?WhatsappVerified
    {
        $clientReference = $payload['client_reference'] ?? null;

        if (blank($clientReference)) {
            return null;
        }

        return $this->verificationModel()::query()
            ->where('user_id', $clientReference)
            ->first();
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
