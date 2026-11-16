<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\Test\Fixtures\ReturnsFromToZapmizer;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\ZapmizerMessage;

class ZapmizerChannelTest extends TestCase
{
    /** @var array<int, NotificationSent> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('zapmizer.api_token', 'team-token');
        config()->set('zapmizer.base_uri', 'https://zapmizer.test/api/');
        Http::fake(['zapmizer.test/*' => Http::response(SentMessageTest::QR, 200, ['Content-Type' => 'application/json'])]);
        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            $this->events[] = $event;
        });
    }

    protected function notify(\Closure $build, ?string $route = '5511999999999'): void
    {
        $notifiable = new AnonymousNotifiable();

        if ($route !== null) {
            $notifiable->route('zapmizer', $route);
        }

        $notifiable->notify(new ReturnsFromToZapmizer($build));
    }

    public function testABuilderThatWasNotSentIsSentByTheChannel()
    {
        $this->notify(fn () => ZapmizerMessage::create('5581911110000', '5511988887777')->text('Hello'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://zapmizer.test/api/messages'
            && $request['to'] === '5511988887777'
            && $request['from'] === '5581911110000'
            && $request['metadata']['text'] === 'Hello');
        $this->assertCount(1, $this->events);
        $this->assertInstanceOf(SentMessage::class, $this->events[0]->response['message']);
        $this->assertSame('9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $this->events[0]->response['message']->id);
    }

    public function testABuilderWithoutTheToGoesToTheRouteOfTheNotifiable()
    {
        $this->notify(fn () => ZapmizerMessage::create(from: '5581911110000')->text('Hello'), '5511977776666');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['to'] === '5511977776666');
    }

    public function testABuilderWithoutTheToAndWithoutARouteSendsNothing()
    {
        $this->notify(fn () => ZapmizerMessage::create(from: '5581911110000')->text('Hello'), null);

        Http::assertNothingSent();
    }

    public function testASentMessageIsNotSentAgain()
    {
        $this->notify(fn () => ZapmizerMessage::create('5581911110000', '5511988887777')->text('Hello')->send());

        Http::assertSentCount(1);
        $this->assertInstanceOf(SentMessage::class, $this->events[0]->response['message']);
    }

    public function testABuilderAlreadySentIsNotSentAgain()
    {
        // The 0.x way: send() answered the builder itself, and toZapmizer()
        // returned it after sending.
        $this->notify(function () {
            $message = ZapmizerMessage::create('5581911110000', '5511988887777')->text('Hello');
            $message->send();

            return $message;
        });

        Http::assertSentCount(1);
        $this->assertInstanceOf(SentMessage::class, $this->events[0]->response['message']);
    }

    public function testNullSendsNothing()
    {
        $this->notify(fn () => null);

        Http::assertNothingSent();
        $this->assertNull($this->events[0]->response['message'] ?? null);
    }
}
