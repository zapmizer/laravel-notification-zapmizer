<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Models\WebhookEvent;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;

class WebhookTest extends TestCase
{
    protected const SECRET = 'whsec_test';

    protected function defineEnvironment($app)
    {
        $app['config']->set('zapmizer.webhook_secret', static::SECRET);
    }

    protected function defineDatabaseMigrations()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->timestamps();
        });

        $migration = include __DIR__ . '/../../database/migrations/create_whatsapp_verifieds_table.php.stub';
        $migration->up();

        $migration = include __DIR__ . '/../../database/migrations/create_zapmizer_webhook_events_table.php.stub';
        $migration->up();
    }

    protected function makeAwaitingUser(): User
    {
        $user = User::create(['name' => 'Test', 'whatsapp_number' => null]);
        $user->whatsappVerification()->create([
            'number' => null,
            'verification_id' => 'vps_abc123',
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        return $user;
    }

    /**
     * Deliver a webhook signed exactly like Zapbot does.
     */
    protected function deliver(array $payload, ?string $secret = null, ?string $rawBodyOverride = null)
    {
        $body = $rawBodyOverride ?? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $signature = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $body, $secret ?? static::SECRET));

        return $this->call('POST', '/zapmizer/webhook', [], [], [], [
            'HTTP_X-Zapbot-Signature' => $signature,
            'HTTP_X-Zapbot-Event-Id' => $payload['event_id'] ?? 'evt_test',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    protected function verifiedPayload(User $user, string $eventId = 'evt_1_verified'): array
    {
        return [
            'event_id' => $eventId,
            'type' => 'verify_number.verified',
            'number' => '5511999999999',
            'status' => 'verified',
            'verified_at' => '2026-06-10T00:00:00.000000Z',
            'failure_reason' => null,
            'client_reference' => (string) $user->id,
        ];
    }

    public function testInvalidSignatureIsRejectedAndMarksNothing()
    {
        Event::fake([WhatsappVerifiedEvent::class]);

        $user = $this->makeAwaitingUser();

        $response = $this->deliver($this->verifiedPayload($user), secret: 'whsec_wrong');

        $response->assertStatus(401);
        $this->assertFalse($user->hasVerifiedWhatsapp());
        Event::assertNotDispatched(WhatsappVerifiedEvent::class);
    }

    public function testTamperedBodyIsRejected()
    {
        $user = $this->makeAwaitingUser();

        $payload = $this->verifiedPayload($user);
        $tampered = json_encode([...$payload, 'client_reference' => '999'], JSON_UNESCAPED_SLASHES);

        // Signature computed over the original body, but a different body delivered.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $body, static::SECRET));

        $this->call('POST', '/zapmizer/webhook', [], [], [], [
            'HTTP_X-Zapbot-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $tampered)->assertStatus(401);

        $this->assertFalse($user->hasVerifiedWhatsapp());
    }

    public function testValidConfirmationMarksAsVerifiedAndFiresEvent()
    {
        Event::fake([WhatsappVerifiedEvent::class]);

        $user = $this->makeAwaitingUser();

        $response = $this->deliver($this->verifiedPayload($user));

        $response->assertOk();
        $response->assertJson(['received' => true, 'handled' => true]);

        $this->assertTrue($user->hasVerifiedWhatsapp());

        $record = $user->whatsappVerification()->first();
        $this->assertEquals('5511999999999', $record->number);
        $this->assertNotNull($record->verified_at);

        // The delivery is recorded in its own table, payload included.
        $event = WebhookEvent::firstWhere('event_id', 'evt_1_verified');
        $this->assertNotNull($event);
        $this->assertTrue($event->handled);
        $this->assertEquals('verify_number.verified', $event->type);
        $this->assertEquals('5511999999999', $event->payload['number']);

        Event::assertDispatched(WhatsappVerifiedEvent::class, fn ($e) => $e->verification->is($record));
    }

    public function testRedeliveryOfSameEventHasNoDuplicateEffect()
    {
        Event::fake([WhatsappVerifiedEvent::class]);

        $user = $this->makeAwaitingUser();
        $payload = $this->verifiedPayload($user);

        $this->deliver($payload)->assertOk();
        $this->deliver($payload)->assertOk()->assertJson(['handled' => true, 'duplicate' => true]);

        $this->assertTrue($user->hasVerifiedWhatsapp());
        $this->assertEquals(1, WhatsappVerified::count());
        $this->assertEquals(1, WebhookEvent::count());
        Event::assertDispatchedTimes(WhatsappVerifiedEvent::class, 1);
    }

    public function testOutOfOrderRedeliveryIsStillDeduplicated()
    {
        Event::fake([WhatsappVerifiedEvent::class]);

        $user = $this->makeAwaitingUser();
        $verified = $this->verifiedPayload($user);
        $expired = [...$verified, 'event_id' => 'evt_1_expired', 'type' => 'verify_number.expired', 'status' => 'expired'];

        // expired arrives first, then verified, then expired is REDELIVERED:
        // it must be deduplicated, not re-applied over the verified state.
        $this->deliver($expired)->assertOk();
        $this->deliver($verified)->assertOk();
        $this->deliver($expired)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertTrue($user->hasVerifiedWhatsapp());
        Event::assertDispatchedTimes(WhatsappVerifiedEvent::class, 1);
    }

    public function testFailureEventUpdatesStateWithoutVerifying()
    {
        Event::fake([WhatsappVerifiedEvent::class]);

        $user = $this->makeAwaitingUser();

        $this->deliver([
            'event_id' => 'evt_1_expired',
            'type' => 'verify_number.expired',
            'number' => '5511999999999',
            'status' => 'expired',
            'verified_at' => null,
            'failure_reason' => 'ttl',
            'client_reference' => (string) $user->id,
        ])->assertOk();

        $this->assertFalse($user->hasVerifiedWhatsapp());
        $this->assertEquals(WhatsappVerified::STATUS_FAILED, $user->whatsappVerification()->first()->status);
        Event::assertNotDispatched(WhatsappVerifiedEvent::class);
    }

    public function testUnknownClientReferenceIsAcknowledgedButNotHandled()
    {
        $user = $this->makeAwaitingUser();

        $payload = [...$this->verifiedPayload($user), 'client_reference' => '424242'];

        $this->deliver($payload)->assertOk()->assertJson(['received' => true, 'handled' => false]);

        $this->assertFalse($user->hasVerifiedWhatsapp());

        // Still recorded for auditing, flagged as unhandled.
        $this->assertFalse(WebhookEvent::firstWhere('event_id', $payload['event_id'])->handled);
    }

    public function testWebhookRouteIsPublic()
    {
        // No auth, no CSRF: an unauthenticated, signed request goes through.
        $user = $this->makeAwaitingUser();

        $this->assertGuest();
        $this->deliver($this->verifiedPayload($user))->assertOk();
    }
}
