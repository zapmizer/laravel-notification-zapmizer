<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;

class WebhookTest extends TestCase
{
    // Every delivery here is unsigned, as Zapmizer sends `verify_number.*`
    // (outside the bot, no signature) — no flag needed. The signed path and
    // the refusal of any other unsigned event are in WebhookSignatureTest.

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

        $migration = include __DIR__ . '/../../database/migrations/create_zapmizer_connections_table.php.stub';
        $migration->up();
    }

    protected function makeAwaitingUser(string $number = '5511999999999'): User
    {
        $user = User::create(['name' => 'Test', 'whatsapp_number' => $number]);
        $user->whatsappVerification()->create([
            'number' => $number,
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        return $user;
    }

    /**
     * Deliver a webhook with the team-webhook payload shape Zapmizer uses.
     */
    protected function deliver(string $name, array $data)
    {
        return $this->postJson('/zapmizer/webhook', ['name' => $name, 'data' => $data]);
    }

    public function testVerifiedEventMarksAsVerifiedAndFiresEvents()
    {
        Event::fake([WhatsappVerifiedEvent::class, WebhookReceived::class, WebhookHandled::class]);

        $user = $this->makeAwaitingUser();

        $response = $this->deliver('verify_number.verified', [
            'number' => '5511999999999',
            'from' => '5581999999999',
        ]);

        $response->assertOk()->assertSeeText('Webhook Handled');

        $this->assertTrue($user->hasVerifiedWhatsapp());

        $record = $user->whatsappVerification()->first();
        $this->assertEquals('5511999999999', $record->number);
        $this->assertNotNull($record->verified_at);

        Event::assertDispatched(WhatsappVerifiedEvent::class, fn ($e) => $e->verification->is($record));
        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(WebhookHandled::class);
    }

    public function testCorrelationToleratesTheBrazilianExtra9()
    {
        // Stored without the extra 9; the canonical number arrives with it.
        $user = $this->makeAwaitingUser('551199999999');

        $this->deliver('verify_number.verified', [
            'number' => '5511999999999',
            'from' => '5581999999999',
        ])->assertOk()->assertSeeText('Webhook Handled');

        $this->assertTrue($user->hasVerifiedWhatsapp());
    }

    public function testRedeliveryHasNoDuplicateEffect()
    {
        Event::fake([WhatsappVerifiedEvent::class]);

        $user = $this->makeAwaitingUser();
        $data = ['number' => '5511999999999', 'from' => '5581999999999'];

        $this->deliver('verify_number.verified', $data)->assertOk();
        $firstVerifiedAt = $user->whatsappVerification()->first()->verified_at;

        $this->deliver('verify_number.verified', $data)->assertOk();

        $this->assertTrue($user->hasVerifiedWhatsapp());
        $this->assertEquals(1, WhatsappVerified::count());
        // verified_at is preserved on redelivery — the effect is idempotent.
        $this->assertEquals($firstVerifiedAt, $user->whatsappVerification()->first()->verified_at);
    }

    public function testFailedEventMovesStateToFailed()
    {
        Event::fake([WhatsappVerifiedEvent::class]);

        $user = $this->makeAwaitingUser();

        $this->deliver('verify_number.failed', [
            'number' => '5511999999999',
            'from' => '5581999999999',
            'reason' => 'too_many_attempts',
        ])->assertOk()->assertSeeText('Webhook Handled');

        $this->assertFalse($user->hasVerifiedWhatsapp());
        $this->assertEquals(WhatsappVerified::STATUS_FAILED, $user->whatsappVerification()->first()->status);
        Event::assertNotDispatched(WhatsappVerifiedEvent::class);
    }

    public function testLateFailedEventNeverDowngradesAVerifiedNumber()
    {
        $user = $this->makeAwaitingUser();
        $data = ['number' => '5511999999999', 'from' => '5581999999999'];

        $this->deliver('verify_number.verified', $data)->assertOk();
        $this->deliver('verify_number.failed', [...$data, 'reason' => 'too_many_attempts'])
            ->assertOk()->assertSeeText('Webhook Received');

        $this->assertTrue($user->hasVerifiedWhatsapp());
    }

    public function testUnknownNumberIsAcknowledgedButNotHandled()
    {
        $user = $this->makeAwaitingUser();

        $this->deliver('verify_number.verified', ['number' => '5599888887777', 'from' => '5581999999999'])
            ->assertOk()->assertSeeText('Webhook Received');

        $this->assertFalse($user->hasVerifiedWhatsapp());
    }

    public function testUnknownEventNameFallsThroughToMissingMethod()
    {
        Event::fake([WebhookHandled::class, WebhookReceived::class]);

        $this->makeAwaitingUser();

        // A verify_number event the package has no handler for.
        $this->deliver('verify_number.expired', ['number' => '5511999999999'])
            ->assertOk()->assertSeeText('Webhook Received');

        Event::assertDispatched(WebhookReceived::class);
        Event::assertNotDispatched(WebhookHandled::class);
    }

    public function testBotEventsAreNotAcceptedUnsigned()
    {
        Event::fake([WebhookReceived::class]);

        $this->makeAwaitingUser();

        $this->deliver('message', [['id' => ['_serialized' => 'x'], 'from' => '5581999998888@c.us']])->assertStatus(401);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testMalformedPayloadIsRejected()
    {
        // Unsigned and not even JSON: refused by the signature check, before
        // the controller's own 400.
        $this->call('POST', '/zapmizer/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json')
            ->assertStatus(401);
    }

    public function testWebhookRouteIsPublic()
    {
        // No auth, no CSRF: Zapmizer's server-to-server POST goes through.
        $user = $this->makeAwaitingUser();

        $this->assertGuest();
        $this->deliver('verify_number.verified', ['number' => '5511999999999', 'from' => null])->assertOk();
    }
}
