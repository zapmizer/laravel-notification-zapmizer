<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Facades\Zapmizer;
use NotificationChannels\Zapmizer\Messages\MessageStatus;
use NotificationChannels\Zapmizer\Messages\MetaMessageStatus;
use NotificationChannels\Zapmizer\Messages\QrMessageStatus;
use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\Messages\SentTemplate;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\ReturnsFromToZapmizer;
use NotificationChannels\Zapmizer\Test\Fixtures\SendsThroughZapmizer;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Testing\FakeSend;
use NotificationChannels\Zapmizer\Testing\ZapmizerFake;
use NotificationChannels\Zapmizer\Zapmizer as ZapmizerClient;
use NotificationChannels\Zapmizer\ZapmizerMessage;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;

class ZapmizerFakeTest extends TestCase
{
    use CreatesConnectionTables;

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.api_token', 'app-token');
        $app['config']->set('zapmizer.base_uri', 'https://zapmizer.test/api/');
        $app['config']->set('zapmizer.api_version', '2025-06-27');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Anything that leaves for real would land here: every test ends
        // with Http::assertNothingSent().
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    public function testFakeReturnsTheFakeAndRegistersIt()
    {
        $fake = Zapmizer::fake();

        $this->assertInstanceOf(ZapmizerFake::class, $fake);
        $this->assertSame($fake, app(ZapmizerFake::class));
    }

    public function testTheFacadeSendsThroughTheFake()
    {
        Zapmizer::fake();

        $sent = Zapmizer::sendMessage(['type' => 'chat', 'from' => '5581911110000', 'to' => '5511999999999', 'metadata' => ['text' => 'Olá']]);

        $this->assertInstanceOf(SentMessage::class, $sent);
        $this->assertSame('5511999999999@c.us', $sent->widTo);
        $this->assertSame('5581911110000@c.us', $sent->widFrom);
        $this->assertTrue($sent->isTrackable());

        Zapmizer::assertSent(fn (FakeSend $send) => $send->to === '5511999999999'
            && $send->from === '5581911110000'
            && $send->text === 'Olá'
            && $send->type === 'chat'
            && $send->kind === FakeSend::MESSAGE
            && $send->token === 'app-token'
            && $send->connection === null
            && $send->response === $sent);
        Zapmizer::assertSentTimes(1);
    }

    public function testTheBuilderSendsThroughTheFakeWithAnIdPerSend()
    {
        Zapmizer::fake();

        $first = ZapmizerMessage::create('5581911110000', '5511999999999')->text('Um')->send();
        $second = ZapmizerMessage::create('5581911110000', '5511988887777@c.us')->text('Dois')->send();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('5511988887777@c.us', $second->widTo);
        Zapmizer::assertSentTimes(2);
        Zapmizer::assertSent(fn (FakeSend $send) => $send->text === 'Dois' && $send->to === '5511988887777@c.us');
    }

    public function testAFileSendIsRecordedWithItsCaption()
    {
        Zapmizer::fake();

        ZapmizerMessage::create('5581911110000', '5511999999999')->image('/tmp/nota.png', 'Sua nota')->send();
        ZapmizerMessage::create('5581911110000', '5511999999999')->document('/tmp/boleto.pdf', 'Seu boleto')->send();

        Zapmizer::assertSent(fn (FakeSend $send) => $send->kind === FakeSend::FILE
            && $send->type === 'image'
            && $send->file === '/tmp/nota.png'
            && $send->filename() === 'nota.png'
            && $send->text === 'Sua nota');
        Zapmizer::assertSent(fn (FakeSend $send) => $send->type === 'document' && $send->hasFile());
        $this->assertCount(1, Zapmizer::sent(fn (FakeSend $send) => $send->type === 'document'));
    }

    public function testTheChannelSendsThroughTheFake()
    {
        Zapmizer::fake();
        $events = [];
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$events) {
            $events[] = $event;
        });

        (new AnonymousNotifiable())->route('zapmizer', '5511977776666')
            ->notify(new ReturnsFromToZapmizer(fn () => ZapmizerMessage::create(from: '5581911110000')->text('Pedido saiu')));
        (new AnonymousNotifiable())->route('zapmizer', '5511966665555')->notify(new SendsThroughZapmizer());

        Zapmizer::assertSentTimes(2);
        Zapmizer::assertSent(fn (FakeSend $send) => $send->to === '5511977776666' && $send->text === 'Pedido saiu');
        Zapmizer::assertSent(fn (FakeSend $send) => $send->to === '5511966665555' && $send->text === 'Hello');
        $this->assertInstanceOf(SentMessage::class, $events[0]->response['message']);
        $this->assertSame('5511977776666@c.us', $events[0]->response['message']->widTo);
    }

    public function testTheSendsOfAConnectionGoThroughTheFakeWithItsToken()
    {
        Zapmizer::fake();
        $team = $this->connectedTeam(number: '5581922220000');
        $connection = $team->zapmizerConnection;

        $team->zapmizerMessage('5511999999999')->text('Pelo número do time')->send();
        $team->zapmizer()->sendMessage(['type' => 'chat', 'from' => '5581922220000', 'to' => '5511988887777', 'metadata' => ['text' => 'Direto']]);
        $connection->message('5511977776666')->text('Pela conexão')->send();

        Zapmizer::assertSentTimes(3);
        Zapmizer::assertSentTimes(3, fn (FakeSend $send) => $send->token === 'token-5581922220000'
            && $send->connection !== null
            && $send->connection->is($connection)
            && $send->from === '5581922220000');
        Zapmizer::assertNotSent(fn (FakeSend $send) => $send->token === 'app-token');
    }

    public function testClientsMadeByHandOrWithParametersGoThroughTheFake()
    {
        Zapmizer::fake();

        app(ZapmizerClient::class, ['api_token' => 'other-token', 'timeout' => 5])->sendMessage(['to' => '5511999999999', 'metadata' => ['text' => 'A']]);
        (new ZapmizerClient('hand-token', null, 'https://outro.test/api/'))->sendMessage(['to' => '5511988887777', 'metadata' => ['text' => 'B']]);

        Zapmizer::assertSent(fn (FakeSend $send) => $send->token === 'other-token' && $send->text === 'A');
        Zapmizer::assertSent(fn (FakeSend $send) => $send->token === 'hand-token' && $send->baseUri === 'https://outro.test/api');
    }

    public function testTheSendMessageCommandGoesThroughTheFake()
    {
        Zapmizer::fake();
        config()->set('zapmizer.from_number', '5581911110000');

        Artisan::call('zapmizer:send-message', ['message' => 'Pelo console', '--to' => '5511999999999']);

        Zapmizer::assertSent(fn (FakeSend $send) => $send->text === 'Pelo console' && $send->to === '5511999999999');
    }

    public function testATemplateIsRecorded()
    {
        Zapmizer::fake();
        $team = $this->connectedTeam(number: '5581922220000');

        $sent = $team->zapmizer()->sendTemplate(from: '5581922220000', to: '5511999999999', template: 'pedido_saiu', language: 'pt_BR', body: ['Ana', '123'], header: 'Loja');

        $this->assertInstanceOf(SentTemplate::class, $sent);
        $this->assertNotNull($sent->wamid);
        $this->assertSame('5511999999999@c.us', $sent->widTo);
        Zapmizer::assertSent(fn (FakeSend $send) => $send->kind === FakeSend::TEMPLATE
            && $send->type === 'template'
            && $send->template === 'pedido_saiu'
            && $send->language === 'pt_BR'
            && $send->body === ['Ana', '123']
            && $send->header === 'Loja'
            && $send->text === null
            && $send->connection !== null);
    }

    public function testTrackingAFakeSendAsksNothing()
    {
        Zapmizer::fake();

        $sent = Zapmizer::sendMessage(['from' => '5581911110000', 'to' => '5511999999999', 'metadata' => ['text' => 'Oi']]);
        $status = Zapmizer::track($sent);

        $this->assertInstanceOf(QrMessageStatus::class, $status);
        $this->assertSame($sent->id, $status->id);
        $this->assertSame(MessageStatus::QUEUED, $status->status);
        $this->assertSame('5511999999999@c.us', $status->widTo);

        $meta = Zapmizer::track('https://app.zapmizer.com/api/meta-messages/42');

        $this->assertInstanceOf(MetaMessageStatus::class, $meta);
        $this->assertSame('42', $meta->id);
        Zapmizer::assertSentTimes(1);
    }

    public function testNothingSent()
    {
        Zapmizer::fake();

        Zapmizer::assertNothingSent();
        $this->assertTrue(Zapmizer::sent()->isEmpty());

        ZapmizerMessage::create('5581911110000', '5511999999999')->text('Oi')->send();

        $this->assertFails(fn () => Zapmizer::assertNothingSent(), '1 message');
    }

    public function testFailingAssertionsSayWhatWasSent()
    {
        Zapmizer::fake();

        $this->assertFails(fn () => Zapmizer::assertSent(), 'No message was sent');

        ZapmizerMessage::create('5581911110000', '5511999999999')->text('Oi')->send();

        $this->assertFails(fn () => Zapmizer::assertSent(fn (FakeSend $send) => $send->to === '5500000000000'), 'No message matching');
        $this->assertFails(fn () => Zapmizer::assertSentTimes(2), 'sent 1 time');
        $this->assertFails(fn () => Zapmizer::assertNotSent(fn (FakeSend $send) => $send->text === 'Oi'), 'unexpected');
    }

    public function testFailWithMakesEverySendThrowAndRecordsNothing()
    {
        $fake = Zapmizer::fake()->failWith(new ZapmizerUnavailableException('fora do ar'));

        try {
            ZapmizerMessage::create('5581911110000', '5511999999999')->text('Oi')->send();
            $this->fail('Expected the failure.');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertSame('fora do ar', $exception->getMessage());
        }

        Zapmizer::assertNothingSent();

        $fake->failWith(null);
        ZapmizerMessage::create('5581911110000', '5511999999999')->text('Oi')->send();
        Zapmizer::assertSentTimes(1);
    }

    public function testFailWithAClosureChoosesWhichSendFails()
    {
        Zapmizer::fake()->failWith(fn (FakeSend $send) => $send->to === '5511000000000'
            ? new ZapmizerUnavailableException('esse não')
            : null);

        ZapmizerMessage::create('5581911110000', '5511999999999')->text('Vai')->send();

        $this->expectException(ZapmizerUnavailableException::class);

        try {
            ZapmizerMessage::create('5581911110000', '5511000000000')->text('Não vai')->send();
        } finally {
            Zapmizer::assertSentTimes(1);
            Zapmizer::assertNotSent(fn (FakeSend $send) => $send->text === 'Não vai');
        }
    }

    public function testFailWithApiErrorBuildsTheExceptionOfTheApi()
    {
        Zapmizer::fake()->failWithApiError(409, ['error' => ErrorCode::WINDOW_CLOSED, 'message' => 'Janela fechada.', 'window_expires_at' => '2026-11-15T10:00:00Z']);

        try {
            ZapmizerMessage::create('5581911110000', '5511999999999')->text('Oi')->send();
            $this->fail('Expected the failure.');
        } catch (ZapmizerApiException $exception) {
            $this->assertSame(409, $exception->status());
            $this->assertSame(ErrorCode::WINDOW_CLOSED, $exception->error());
            $this->assertSame('2026-11-15T10:00:00Z', $exception->payload()['window_expires_at']);
        }

        Zapmizer::fake()->failWithApiError(401);

        $this->expectException(ZapmizerUnauthorizedException::class);

        Zapmizer::sendMessage(['to' => '5511999999999']);
    }

    public function testTheFakeDoesNotNeedAToken()
    {
        config()->set('zapmizer.api_token', null);
        Zapmizer::fake();

        ZapmizerMessage::create('5581911110000', '5511999999999')->text('Oi')->send();

        Zapmizer::assertSent(fn (FakeSend $send) => $send->token === null);
    }

    public function testAssertingWithoutTheFakeSaysToCallFake()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Zapmizer::fake()');

        Zapmizer::assertNothingSent();
    }

    public function testWithoutTheFakeTheFacadeIsTheClient()
    {
        $this->assertInstanceOf(ZapmizerClient::class, Zapmizer::getFacadeRoot());
        $this->assertSame('app-token', Zapmizer::getToken());
    }

    private function assertFails(\Closure $assertion, string $message): void
    {
        try {
            $assertion();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }

        $this->fail('The assertion did not fail.');
    }
}
