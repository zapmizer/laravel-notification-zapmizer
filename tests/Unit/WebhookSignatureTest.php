<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\WebhookHandled;
use NotificationChannels\Zapmizer\Events\WebhookReceived;
use NotificationChannels\Zapmizer\InboundMessage;
use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Events\WhatsappVerified as WhatsappVerifiedEvent;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyWebhookSignature;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Test\Fixtures\CapturesConnectionWebhookController;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class WebhookSignatureTest extends TestCase
{
    use CreatesConnectionTables, SignsWebhookDeliveries;

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        // The single-tenant secret — the webhook registered by hand.
        $app['config']->set('zapmizer.webhook.secret', 'app-secret');
    }

    protected function defineRoutes($router)
    {
        $router->post('custom-webhook', [CapturesConnectionWebhookController::class, 'handleWebhook'])
            ->middleware(VerifyWebhookSignature::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        CapturesConnectionWebhookController::$seen = null;
        CapturesConnectionWebhookController::$handled = false;
    }

    public function testValidSignatureDispatchesMessageReceivedWithTheConnection()
    {
        Event::fake([MessageReceived::class, WebhookReceived::class, WebhookHandled::class]);
        $team = $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope('5581999998888@c.us', ['id' => 'ABC', 'body' => 'hello']), 'secret-a')
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        $connection = $team->zapmizerConnection;

        Event::assertDispatched(MessageReceived::class, function (MessageReceived $event) use ($connection) {
            return $event->connection->is($connection)
                && $event->message instanceof InboundMessage
                && $event->message->id === 'false_5581999998888@c.us_ABC'
                && $event->message->fromPhone === '5581999998888'
                && $event->message->to === '5581911110000@c.us'
                && $event->message->body === 'hello'
                && $event->payload['name'] === 'message';
        });
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->connection->is($connection));
        Event::assertDispatched(WebhookHandled::class, fn (WebhookHandled $event) => $event->connection->is($connection));
    }

    #[DataProvider('messageVariants')]
    public function testMessageVariantsAreTranslated(string $from, array $overrides, callable $assert)
    {
        Event::fake([MessageReceived::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope($from, $overrides), 'secret-a')->assertOk();

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $assert($event->message));
    }

    public static function messageVariants(): array
    {
        return [
            'group' => ['120363000000000000@g.us', [], fn (InboundMessage $m) => $m->isGroup],
            'fromMe' => ['5581999998888@c.us', ['fromMe' => true], fn (InboundMessage $m) => $m->fromMe],
            'unresolved lid' => ['84474155032797@lid', [], fn (InboundMessage $m) => $m->hasUnresolvedSender],
            'lid resolved via _data.from' => [
                '84474155032797@lid',
                ['_data' => ['from' => ['_serialized' => '5581999998888@c.us']]],
                fn (InboundMessage $m) => !$m->hasUnresolvedSender && $m->fromPhone === '5581999998888',
            ],
            'media' => [
                '5581999998888@c.us',
                ['type' => 'image', 'hasMedia' => true, '_data' => ['mimetype' => 'image/jpeg', 'size' => 99]],
                fn (InboundMessage $m) => $m->hasMedia && $m->mediaMetadata === ['mimetype' => 'image/jpeg', 'size' => 99],
            ],
        ];
    }

    public function testInvalidSignatureIsRefusedWithoutDispatching()
    {
        Event::fake([MessageReceived::class, WebhookReceived::class]);
        Log::spy();
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope(), 'secret-a', signature: 'v1=' . str_repeat('a', 64))
            ->assertStatus(401);

        Event::assertNotDispatched(MessageReceived::class);
        Event::assertNotDispatched(WebhookReceived::class);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => str_contains($message, 'refused by signature')
                && $context['reason'] === 'no known secret signs this delivery'
                && $context['wid'] === '5581911110000@c.us'
                && !isset($context['signature'])
        );
    }

    public function testPreviousSecretStillValidatesDuringRotation()
    {
        Event::fake([MessageReceived::class]);
        $team = $this->connectedTeam('secret-new');
        $team->zapmizerConnection->forceFill(['webhook_previous_secret' => 'secret-old'])->save();

        $this->deliverSigned($this->messageEnvelope(), 'secret-old')->assertOk();

        Event::assertDispatched(MessageReceived::class);
    }

    public function testBothOfferedSignaturesAreTried()
    {
        Event::fake([MessageReceived::class]);
        $this->connectedTeam('secret-a');

        $envelope = $this->messageEnvelope();
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();
        $good = hash_hmac('sha256', $timestamp . '.' . $body, 'secret-a');

        // Zapmizer offers the new signature first and the previous one after.
        $this->deliverSigned($envelope, 'secret-a', $timestamp, 'v1=' . str_repeat('b', 64) . ',v1=' . $good)->assertOk();

        Event::assertDispatched(MessageReceived::class);
    }

    public function testStaleTimestampIsRefused()
    {
        Log::spy();
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope(), 'secret-a', timestamp: now()->subHour()->getTimestamp())
            ->assertStatus(401);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => $context['reason'] === 'timestamp outside the tolerance window'
        );
    }

    public function testUnsignedMessageIsRefused()
    {
        Event::fake([WebhookReceived::class, MessageReceived::class]);
        Log::spy();
        $this->connectedTeam('secret-a');

        // Every bot webhook on Zapmizer has a secret: a `message` without a
        // signature was not sent by Zapmizer.
        $this->deliverUnsigned($this->messageEnvelope())->assertStatus(401);

        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(MessageReceived::class);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => $context['reason'] === 'signature headers missing'
        );
    }

    public function testUnsignedVerifyNumberIsAcceptedWithoutAnyFlag()
    {
        Event::fake([WhatsappVerifiedEvent::class, WebhookReceived::class]);
        $this->connectedTeam('secret-a');
        $user = User::create(['name' => 'Test', 'whatsapp_number' => '5511999999999']);
        $user->whatsappVerification()->create(['number' => '5511999999999', 'status' => WhatsappVerified::STATUS_AWAITING]);

        // verify_number.* is delivered outside the bot, unsigned, by
        // construction — and is the only event accepted that way.
        $this->deliverUnsigned(['name' => 'verify_number.verified', 'data' => ['number' => '5511999999999', 'from' => '5581999999999']])
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        $this->assertTrue($user->fresh()->hasVerifiedWhatsapp());
        Event::assertDispatched(WhatsappVerifiedEvent::class);
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->connection === null);
    }

    #[DataProvider('unsignedNonVerifyEvents')]
    public function testOnlyVerifyNumberPassesUnsigned(array $envelope)
    {
        Event::fake([WebhookReceived::class]);
        $this->connectedTeam('secret-a');

        $this->deliverUnsigned($envelope)->assertStatus(401);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public static function unsignedNonVerifyEvents(): array
    {
        return [
            'qr' => [['name' => 'qr', 'data' => [['qr' => 'x']]]],
            'name that only contains the prefix' => [['name' => 'x.verify_number.verified', 'data' => []]],
            'no name' => [['data' => []]],
        ];
    }

    public function testHalfMissingHeadersAreRefused()
    {
        $this->connectedTeam('secret-a');

        $this->call('POST', '/zapmizer/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZAPMIZER_TIMESTAMP' => (string) now()->getTimestamp(),
        ], json_encode(['name' => 'verify_number.verified', 'data' => []]))->assertStatus(401);
    }

    public function testApplicationSecretSignsDeliveriesWithoutAConnection()
    {
        Event::fake([MessageReceived::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope(), 'app-secret')->assertOk()->assertSeeText('Webhook Handled');

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->connection === null);
    }

    public function testApplicationSecretAndConnectionSecretsCoexist()
    {
        Event::fake([MessageReceived::class]);
        $team = $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope(), 'secret-a')->assertOk();
        $this->deliverSigned($this->messageEnvelope(), 'app-secret')->assertOk();
        $this->deliverSigned($this->messageEnvelope(), 'other')->assertStatus(401);

        Event::assertDispatchedTimes(MessageReceived::class, 2);
        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->connection?->is($team->zapmizerConnection) ?? false);
    }

    public function testEmptyApplicationSecretNeverMatches()
    {
        config()->set('zapmizer.webhook.secret', null);
        $this->connectedTeam('secret-a');

        $this->deliverSigned($this->messageEnvelope(), '')->assertStatus(401);
    }

    public function testConnectionIsFoundByHmacNotByWid()
    {
        Event::fake([MessageReceived::class]);
        $teamA = $this->connectedTeam('secret-a', '5581911110000');
        $teamB = $this->connectedTeam('secret-b', '5581922220000');

        // Signed by B but claiming A's wid: the proof is the HMAC.
        $this->deliverSigned($this->messageEnvelope(), 'secret-b', wid: '5581911110000@c.us')->assertOk();

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->connection->is($teamB->zapmizerConnection));
        $this->assertFalse($teamA->zapmizerConnection->is($teamB->zapmizerConnection));
    }

    public function testUnreadableCredentialDoesNotTakeDownTheOthers()
    {
        Event::fake([MessageReceived::class]);
        Log::spy();

        // The corrupted one is the FIRST candidate (its phone_number matches
        // the X-Wid), so its decryption happens before the good one is tried.
        $rotten = $this->connectedTeam('irrelevant', '5581911110000');
        $good = $this->connectedTeam('secret-good', '5581922220000');

        // Plain text where an encrypted payload should be: what is left of a
        // row written with another APP_KEY.
        DB::table('zapmizer_connections')->where('id', $rotten->zapmizerConnection->id)->update(['webhook_secret' => 'not-encrypted']);

        $this->deliverSigned($this->messageEnvelope(), 'secret-good', wid: '5581911110000@c.us')->assertOk();

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->connection->is($good->zapmizerConnection));
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'unreadable webhook secret'));
    }

    public function testInactiveConnectionIsRefusedWithLog()
    {
        Event::fake([WebhookReceived::class]);
        Log::spy();
        $this->connectedTeam('secret-a', overrides: ['is_active' => false]);

        $this->deliverSigned($this->messageEnvelope(), 'secret-a')->assertStatus(403);

        Event::assertNotDispatched(WebhookReceived::class);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'connection inactive'));
    }

    public function testUnrecognizedMessageShapeIsAcknowledgedButNotHandled()
    {
        Event::fake([MessageReceived::class]);
        $this->connectedTeam('secret-a');

        $this->deliverSigned(['name' => 'message', 'data' => [['from' => 'x@c.us']]], 'secret-a')
            ->assertOk()
            ->assertSeeText('Webhook Received');

        Event::assertNotDispatched(MessageReceived::class);
    }

    public function testConnectionRidesOnTheRequestForCustomHandlers()
    {
        $team = $this->connectedTeam('secret-a');

        $this->deliverSigned(['name' => 'qr', 'data' => [['qr' => 'x']]], 'secret-a', path: '/custom-webhook')
            ->assertOk()
            ->assertSeeText('Webhook Handled');

        $this->assertTrue(CapturesConnectionWebhookController::$handled);
        $this->assertTrue(CapturesConnectionWebhookController::$seen?->is($team->zapmizerConnection));
    }

    public function testMessageReceivedCarriesTheMessageFirst()
    {
        $connection = $this->connectedTeam('secret-a')->zapmizerConnection;
        $message = InboundMessage::fromEnvelope($this->messageEnvelope());

        $event = new MessageReceived($message, $connection, ['name' => 'message']);

        $this->assertSame($message, $event->message);
        $this->assertTrue($event->connection->is($connection));
    }
}
