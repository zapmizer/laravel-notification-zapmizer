<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Models\WebhookEvent;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Support\ZapbotSignature;

/**
 * Class WebhookController.
 *
 * Public endpoint that receives Zapbot's verify-number webhooks. Only
 * deliveries signed with the verification's webhook secret are accepted;
 * everything else is rejected before touching any state.
 *
 * Every accepted delivery is recorded in the zapmizer_webhook_events table,
 * whose unique event_id doubles as the idempotency key — redeliveries (in
 * any order) are acknowledged without reapplying the effect. Correlation
 * uses the `client_reference` the package sent when starting the
 * verification (the owner model's key) — never the phone number, so
 * concurrent attempts can't get mixed up.
 */
class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('zapmizer.webhook_secret');
        $signature = (string) $request->header('X-Zapbot-Signature', '');

        abort_unless(
            $secret !== '' && ZapbotSignature::isValid($signature, $request->getContent(), $secret),
            401,
            'Invalid webhook signature.'
        );

        $payload = json_decode($request->getContent(), true);

        abort_unless(is_array($payload), 400, 'Malformed webhook payload.');

        $eventId = (string) ($payload['event_id'] ?? '');

        if (blank($eventId)) {
            return response()->json(['received' => true, 'handled' => false]);
        }

        $event = $this->webhookEventModel()::query()->firstOrCreate(
            ['event_id' => $eventId],
            [
                'type' => $payload['type'] ?? null,
                'status' => $payload['status'] ?? null,
                'client_reference' => isset($payload['client_reference']) ? (string) $payload['client_reference'] : null,
                'payload' => $payload,
            ],
        );

        // Idempotency: a redelivery of an already-recorded event is just acknowledged.
        if (!$event->wasRecentlyCreated) {
            return response()->json(['received' => true, 'handled' => (bool) $event->handled, 'duplicate' => true]);
        }

        $handled = $this->apply($payload);

        $event->forceFill(['handled' => $handled])->save();

        return response()->json(['received' => true, 'handled' => $handled]);
    }

    /**
     * Apply the event's effect on the verification state. Returns whether
     * the event matched a verification and changed anything.
     */
    protected function apply(array $payload): bool
    {
        $clientReference = $payload['client_reference'] ?? null;

        if (blank($clientReference)) {
            return false;
        }

        $record = $this->verificationModel()::query()
            ->where('user_id', $clientReference)
            ->first();

        if ($record === null) {
            return false;
        }

        $status = $payload['status'] ?? null;

        if ($status === 'verified') {
            $record->forceFill([
                'number' => $payload['number'] ?? $record->number,
                'status' => WhatsappVerified::STATUS_VERIFIED,
                'verified_at' => $payload['verified_at'] ?? $record->freshTimestamp(),
            ])->save();

            event(new WhatsappVerifiedEvent($record));

            return true;
        }

        if (in_array($status, ['expired', 'failed'], true)) {
            $record->forceFill([
                'status' => WhatsappVerified::STATUS_FAILED,
                'verified_at' => null,
            ])->save();

            return true;
        }

        return false;
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
