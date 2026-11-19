<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyWebhookSignature;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The verify_number webhook has its own secret (zapmizer.verify_number.
 * webhook_secret): with it set, those deliveries must be signed with it and
 * only with it; without it they keep arriving unsigned.
 */
class VerifyNumberSignatureTest extends TestCase
{
    use CreatesConnectionTables, SignsWebhookDeliveries;

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.webhook.secret', 'app-secret');
        $app['config']->set('zapmizer.verify_number.webhook_secret', 'verify-secret');
    }

    protected function defineRoutes($router)
    {
        $router->post('probe', fn (Request $request) => response()->json([
            'signed' => $request->attributes->get(VerifyWebhookSignature::VERIFY_NUMBER_SIGNED_ATTRIBUTE),
            'connection' => $request->attributes->get(VerifyWebhookSignature::CONNECTION_ATTRIBUTE),
        ]))->middleware(VerifyWebhookSignature::class);
    }

    private function verifyEnvelope(string $name = 'verify_number.verified'): array
    {
        return [
            'id' => '0199a3c2-7f1e-7b6a-9d2e-' . random_int(100000000000, 999999999999),
            'created_at' => '2026-10-20T14:03:11.482Z',
            'wid' => '5581911110000@c.us',
            'name' => $name,
            'version' => '2026-10-20',
            'data' => ['number' => '5511999999999', 'from' => '5581999999999'],
        ];
    }

    private function awaitingUser(): User
    {
        $user = User::create(['name' => 'Test', 'whatsapp_number' => '5511999999999']);
        $user->whatsappVerification()->create(['number' => '5511999999999', 'status' => WhatsappVerified::STATUS_AWAITING]);

        return $user;
    }

    public function testSignedWithItsOwnSecretIsAccepted()
    {
        Event::fake([WhatsappVerifiedEvent::class, WebhookReceived::class]);
        $user = $this->awaitingUser();

        $this->deliverSigned($this->verifyEnvelope(), 'verify-secret')->assertOk()->assertSeeText('Webhook Handled');

        $this->assertTrue($user->fresh()->hasVerifiedWhatsapp());
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->connection === null);
    }

    public function testTheSignedAttributeIsSetOnlyForSignedVerifyNumber()
    {
        $this->deliverSigned($this->verifyEnvelope(), 'verify-secret', path: '/probe')->assertOk()->assertJson(['signed' => true, 'connection' => null]);
        $this->deliverSigned($this->messageEnvelope(), 'app-secret', path: '/probe')->assertOk()->assertJson(['signed' => null]);
        $this->deliverUnsigned(['name' => 'verify_number.verified'])->assertStatus(401);

        config()->set('zapmizer.verify_number.webhook_secret', null);
        $this->call('POST', '/probe', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->verifyEnvelope()))
            ->assertOk()->assertJson(['signed' => null]);
    }

    public function testRotationOffersBothSignaturesAndEitherOneSigns()
    {
        $envelope = $this->verifyEnvelope();
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();
        $sign = fn (string $secret) => 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        // current + previous: the app still holds the previous one.
        $this->deliverSigned($envelope, 'x', $timestamp, $sign('new-secret') . ',' . $sign('verify-secret'))->assertOk();
        // the app already holds the current one.
        config()->set('zapmizer.verify_number.webhook_secret', 'new-secret');
        $this->deliverSigned($envelope, 'x', $timestamp, $sign('new-secret') . ',' . $sign('verify-secret'))->assertOk();
    }

    public function testOwnSecretDoesNotSignOtherEvents()
    {
        Event::fake([MessageReceived::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope(), 'verify-secret')->assertStatus(401);
        $this->deliverSigned($this->contractEnvelope(), 'verify-secret')->assertStatus(401);

        Event::assertNotDispatched(MessageReceived::class);
    }

    public function testApplicationSecretStillSignsOtherEvents()
    {
        Event::fake([MessageReceived::class]);

        $this->deliverSigned($this->messageEnvelope(), 'app-secret')->assertOk();

        Event::assertDispatched(MessageReceived::class);
    }

    public function testVerifyNumberSignedWithTheApplicationSecretIsRefused()
    {
        Log::spy();
        Event::fake([WhatsappVerifiedEvent::class]);
        $this->awaitingUser();

        $this->deliverSigned($this->verifyEnvelope(), 'app-secret')->assertStatus(401);

        Event::assertNotDispatched(WhatsappVerifiedEvent::class);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($context['reason'] ?? '', 'verify_number'))->once();
    }

    public function testVerifyNumberSignedWithAConnectionSecretIsRefused()
    {
        Event::fake([WhatsappVerifiedEvent::class]);
        $this->connectedTeam('secret-a');
        $this->awaitingUser();

        $this->deliverSigned($this->verifyEnvelope(), 'secret-a')->assertStatus(401);

        Event::assertNotDispatched(WhatsappVerifiedEvent::class);
    }

    public function testVerifyNumberSignedWithoutAConfiguredSecretIsRefusedAndSaysWhy()
    {
        config()->set('zapmizer.verify_number.webhook_secret', null);
        Log::spy();

        $this->deliverSigned($this->verifyEnvelope(), 'app-secret')->assertStatus(401);
        $this->deliverSigned($this->verifyEnvelope(), 'anything')->assertStatus(401);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($context['reason'] ?? '', 'ZAPMIZER_VERIFY_NUMBER_WEBHOOK_SECRET'))->twice();
    }

    public function testVerifyNumberUsesNoConnectionQueries()
    {
        $this->connectedTeam('secret-a');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->deliverSigned($this->verifyEnvelope(), 'wrong')->assertStatus(401);

        $this->assertStringNotContainsString('zapmizer_connections', json_encode(DB::getQueryLog()));
    }

    public function testStaleTimestampIsRefused()
    {
        $this->deliverSigned($this->verifyEnvelope(), 'verify-secret', now()->getTimestamp() - 3600)->assertStatus(401);
    }

    public function testUnsignedWithTheSecretSetIsRefused()
    {
        Event::fake([WhatsappVerifiedEvent::class]);
        $this->awaitingUser();

        $this->deliverUnsigned($this->verifyEnvelope())->assertStatus(401);

        Event::assertNotDispatched(WhatsappVerifiedEvent::class);
    }

    /** @dataProvider emptySecrets */
    #[DataProvider('emptySecrets')]
    public function testUnsignedWithoutTheSecretIsAccepted($secret)
    {
        config()->set('zapmizer.verify_number.webhook_secret', $secret);
        $user = $this->awaitingUser();

        $this->deliverUnsigned($this->verifyEnvelope())->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedWhatsapp());
    }

    public static function emptySecrets(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }

    public function testUnsignedOtherEventsStayRefused()
    {
        $this->deliverUnsigned(['name' => 'message', 'data' => []])->assertStatus(401);
    }

    public static function nearMissNames(): array
    {
        return [
            'case' => ['Verify_Number.verified'],
            'leading space' => [' verify_number.verified'],
            'dash' => ['verify-number.verified'],
            'camel' => ['verifyNumber.verified'],
        ];
    }

    /** @dataProvider nearMissNames */
    #[DataProvider('nearMissNames')]
    public function testANearMissNameSignedWithAnotherSecretIsRefused(string $name)
    {
        $user = $this->awaitingUser();
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->verifyEnvelope($name), 'app-secret')->assertStatus(401);
        $this->deliverSigned($this->verifyEnvelope($name), 'secret-a')->assertStatus(401);

        $this->assertFalse($user->fresh()->hasVerifiedWhatsapp());
    }

    /** @dataProvider nearMissNames */
    #[DataProvider('nearMissNames')]
    public function testANearMissNameSignedWithTheVerifySecretIsInTheVerifyScope(string $name)
    {
        $this->deliverSigned($this->verifyEnvelope($name), 'verify-secret', path: '/probe')->assertOk()->assertJson(['signed' => true]);
        $this->deliverUnsigned($this->verifyEnvelope($name))->assertStatus(401);
    }
}
