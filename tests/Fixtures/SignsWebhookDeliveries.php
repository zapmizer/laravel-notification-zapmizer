<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Testing\TestResponse;

trait SignsWebhookDeliveries
{
    /**
     * A `message` envelope as whatsapp-web.js hands it over.
     *
     * @param array<string, mixed> $overrides
     */
    protected function messageEnvelope(string $from = '5581999998888@c.us', array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'MSG' . random_int(10000000, 99999999);
        unset($overrides['id']);

        return [
            'name' => 'message',
            'data' => [array_merge([
                'id' => ['id' => $id, '_serialized' => "false_{$from}_{$id}"],
                'from' => $from,
                'to' => '5581911110000@c.us',
                'body' => 'hi',
                'type' => 'chat',
                'hasMedia' => false,
                'fromMe' => false,
                'timestamp' => now()->getTimestamp(),
                '_data' => [],
            ], $overrides)],
        ];
    }

    /**
     * Deliver a signed webhook, exactly as Zapmizer does.
     */
    protected function deliverSigned(
        array $envelope,
        string $secret,
        ?int $timestamp = null,
        ?string $signature = null,
        string $wid = '5581911110000@c.us',
        string $path = '/zapmizer/webhook',
    ): TestResponse {
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->getTimestamp();
        $signature ??= 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WID' => $wid,
            'HTTP_X_ZAPMIZER_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_ZAPMIZER_SIGNATURE' => $signature,
        ], $body);
    }

    /**
     * Deliver an unsigned webhook — what Zapmizer does for `verify_number.*`
     * only.
     */
    protected function deliverUnsigned(array $envelope, string $wid = '5581911110000@c.us'): TestResponse
    {
        return $this->call('POST', '/zapmizer/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WID' => $wid,
        ], json_encode($envelope));
    }
}
