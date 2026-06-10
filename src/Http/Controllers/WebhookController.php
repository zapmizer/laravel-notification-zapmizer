<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Support\ZapbotSignature;

/**
 * Class WebhookController.
 *
 * Public endpoint that receives Zapbot's verify-number webhooks. Only
 * deliveries signed with the verification's webhook secret are accepted;
 * everything else is rejected before touching any state.
 *
 * Correlation uses the `client_reference` the package sent when starting
 * the verification (the owner model's key) — never the phone number, so
 * concurrent attempts can't get mixed up. Redeliveries of the same
 * `event_id` are acknowledged without applying the effect twice.
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
        $clientReference = $payload['client_reference'] ?? null;

        if (blank($eventId) || blank($clientReference)) {
            return response()->json(['received' => true, 'handled' => false]);
        }

        $record = $this->verificationModel()::query()
            ->where('user_id', $clientReference)
            ->first();

        if ($record === null) {
            return response()->json(['received' => true, 'handled' => false]);
        }

        // Idempotency: a redelivery of an already-applied event is just acknowledged.
        if ($record->last_event_id === $eventId) {
            return response()->json(['received' => true, 'handled' => true, 'duplicate' => true]);
        }

        $status = $payload['status'] ?? null;

        if ($status === 'verified') {
            $record->forceFill([
                'number' => $payload['number'] ?? $record->number,
                'status' => WhatsappVerified::STATUS_VERIFIED,
                'verified_at' => $payload['verified_at'] ?? $record->freshTimestamp(),
                'last_event_id' => $eventId,
            ])->save();

            event(new WhatsappVerifiedEvent($record));
        } elseif (in_array($status, ['expired', 'failed'], true)) {
            $record->forceFill([
                'status' => WhatsappVerified::STATUS_FAILED,
                'verified_at' => null,
                'last_event_id' => $eventId,
            ])->save();
        } else {
            return response()->json(['received' => true, 'handled' => false]);
        }

        return response()->json(['received' => true, 'handled' => true]);
    }

    /**
     * @return class-string<WhatsappVerified>
     */
    protected function verificationModel(): string
    {
        return config('zapmizer.models.whatsapp_verified', WhatsappVerified::class);
    }
}
