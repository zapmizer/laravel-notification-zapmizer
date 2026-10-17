<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Messages\MessageStatus;
use NotificationChannels\Zapmizer\Messages\MetaMessageStatus;
use NotificationChannels\Zapmizer\Messages\QrMessageStatus;
use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Zapmizer;
use PHPUnit\Framework\Attributes\DataProvider;

class MessageTrackingTest extends TestCase
{
    use AssertsContract;

    public const QR = '{"data":{"id":"9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a","wapp_message_id":"true_551199999999@c.us_3EB0A1B2C3","wid_from":"5581999990000@c.us","wid_to":"551199999999@c.us","message_status":2,"message_status_name":"DELIVERED","message_status_label":"Entregue","error":null,"error_message":null,"timestamp":1760700000,"links":{"self":"https://app.zapmizer.com/api/sent-messages/9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a"}}}';

    public const QR_FAILED = '{"data":{"id":"9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a","wapp_message_id":null,"wid_from":"5581999990000@c.us","wid_to":"551199999999@c.us","message_status":-4,"message_status_name":"FAILED","message_status_label":"Falhou","error":"send_failed","error_message":"O número está offline.","timestamp":null,"links":{"self":"https://app.zapmizer.com/api/sent-messages/9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a"}}}';

    public const META = '{"data":{"id":42,"bot_instance_id":7,"wamid":"wamid.HBgMNTUxMTk5OTk5OTk5ORUCABEYEjA","wid_from":"5581999990000@c.us","wid_to":"5511999999999@c.us","message_status":3,"message_status_name":"read","payload":[],"media_url":"","error_reason":null,"created_at":"2026-10-17T12:00:00.000000Z","links":{"self":"https://app.zapmizer.com/api/meta-messages/42"}}}';

    public const META_FAILED = '{"data":{"id":43,"bot_instance_id":7,"wamid":"wamid.X","wid_from":"5581999990000@c.us","wid_to":"5511999999999@c.us","message_status":-1,"message_status_name":"failed","payload":[],"media_url":"","error_reason":"(#131047) Re-engagement message","created_at":null,"links":{"self":"https://app.zapmizer.com/api/meta-messages/43"}}}';

    protected function client(RecordingTransport $transport, string $base = 'http://localhost/api'): Zapmizer
    {
        return new Zapmizer('bot-token', $transport, $base, '2025-06-27');
    }

    protected static function answer(string $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $body);
    }

    public function testSentMessageReadsTheQrTracking()
    {
        $this->assertMatchesContract('GET', '/sent-messages/{id}', 200, self::QR);
        $transport = new RecordingTransport(self::answer(self::QR));

        $status = $this->client($transport)->sentMessage('9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a');

        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertSame('http://localhost/api/sent-messages/9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $transport->calls[0]['url']);
        $this->assertSame('Bearer bot-token', $transport->calls[0]['options']['headers']['Authorization']);
        $this->assertSame('application/json', $transport->calls[0]['options']['headers']['Accept']);

        $this->assertInstanceOf(QrMessageStatus::class, $status);
        $this->assertSame('9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $status->id);
        $this->assertSame('true_551199999999@c.us_3EB0A1B2C3', $status->wappMessageId);
        $this->assertSame('5581999990000@c.us', $status->widFrom);
        $this->assertSame('551199999999@c.us', $status->widTo);
        $this->assertSame(MessageStatus::DELIVERED, $status->status);
        $this->assertSame('DELIVERED', $status->statusName);
        $this->assertSame('Entregue', $status->statusLabel);
        $this->assertNull($status->error);
        $this->assertNull($status->errorMessage);
        $this->assertSame(1760700000, $status->timestamp);
        $this->assertSame('https://app.zapmizer.com/api/sent-messages/9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $status->selfUrl);
        $this->assertTrue($status->isDelivered());
        $this->assertFalse($status->isRead());
        $this->assertFalse($status->isFailed());
        $this->assertFalse($status->isFinal());
        $this->assertSame(json_decode(self::QR, true), $status->raw());
    }

    public function testAFailedQrSendCarriesTheReason()
    {
        $this->assertMatchesContract('GET', '/sent-messages/{id}', 200, self::QR_FAILED);

        $status = $this->client(new RecordingTransport(self::answer(self::QR_FAILED)))->sentMessage('9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a');

        $this->assertSame(MessageStatus::FAILED_BEFORE_SENDING, $status->status);
        $this->assertNull($status->wappMessageId);
        $this->assertNull($status->timestamp);
        $this->assertSame('send_failed', $status->error);
        $this->assertSame('O número está offline.', $status->errorMessage);
        $this->assertTrue($status->isFailed());
        $this->assertTrue($status->isFinal());
        $this->assertFalse($status->isDelivered());
    }

    public function testMetaMessageReadsTheOfficialTracking()
    {
        $this->assertMatchesContract('GET', '/meta-messages/{id}', 200, self::META);
        $transport = new RecordingTransport(self::answer(self::META));

        $status = $this->client($transport)->metaMessage(42);

        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertSame('http://localhost/api/meta-messages/42', $transport->calls[0]['url']);
        $this->assertSame('Bearer bot-token', $transport->calls[0]['options']['headers']['Authorization']);

        $this->assertInstanceOf(MetaMessageStatus::class, $status);
        $this->assertSame('42', $status->id);
        $this->assertSame(7, $status->botInstanceId);
        $this->assertSame('wamid.HBgMNTUxMTk5OTk5OTk5ORUCABEYEjA', $status->wamid);
        $this->assertSame('5581999990000@c.us', $status->widFrom);
        $this->assertSame('5511999999999@c.us', $status->widTo);
        $this->assertSame(MessageStatus::READ, $status->status);
        $this->assertSame('read', $status->statusName);
        $this->assertNull($status->mediaUrl);
        $this->assertNull($status->errorReason);
        $this->assertInstanceOf(CarbonImmutable::class, $status->createdAt);
        $this->assertSame('2026-10-17T12:00:00+00:00', $status->createdAt->toIso8601String());
        $this->assertSame('https://app.zapmizer.com/api/meta-messages/42', $status->selfUrl);
        $this->assertTrue($status->isRead());
        $this->assertTrue($status->isDelivered());
        $this->assertTrue($status->isFinal());
        $this->assertSame(json_decode(self::META, true), $status->raw());
    }

    public function testAFailedOfficialSendCarriesTheReason()
    {
        $this->assertMatchesContract('GET', '/meta-messages/{id}', 200, self::META_FAILED);

        $status = $this->client(new RecordingTransport(self::answer(self::META_FAILED)))->metaMessage('43');

        $this->assertSame(MessageStatus::FAILED, $status->status);
        $this->assertSame('(#131047) Re-engagement message', $status->errorReason);
        $this->assertNull($status->createdAt);
        $this->assertTrue($status->isFailed());
    }

    public function testTheIdIsEncodedInThePath()
    {
        $this->assertMatchesContract('GET', '/sent-messages/{id}', 200, self::QR);
        $transport = new RecordingTransport(self::answer(self::QR));

        $this->client($transport)->sentMessage('a/b?c');

        $this->assertSame('http://localhost/api/sent-messages/a%2Fb%3Fc', $transport->calls[0]['url']);
    }

    public function testAnEmptyIdFailsBeforeAnyRequest()
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport);

        foreach ([fn () => $client->sentMessage(''), fn () => $client->metaMessage(''), fn () => $client->metaMessage(0)] as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('id', $exception->getMessage());
            }
        }

        $this->assertSame([], $transport->calls);
    }

    public function testWithoutTokenTrackingFailsBeforeAnyRequest()
    {
        $transport = new RecordingTransport();
        $client = new Zapmizer(null, $transport, 'http://localhost/api');

        foreach ([fn () => $client->sentMessage('1'), fn () => $client->metaMessage(1)] as $call) {
            try {
                $call();
                $this->fail('Expected ZapmizerUnauthorizedException.');
            } catch (ZapmizerUnauthorizedException $exception) {
                $this->assertSame(401, $exception->status());
            }
        }

        $this->assertSame([], $transport->calls);
    }

    public function testTrackFollowsAQrSendThroughTheConfiguredBase()
    {
        $transport = new RecordingTransport(self::answer(self::QR));
        $sent = SentMessage::fromArray(json_decode(SentMessageTest::QR, true));

        $status = $this->client($transport)->track($sent);

        $this->assertInstanceOf(QrMessageStatus::class, $status);
        $this->assertSame('http://localhost/api/sent-messages/9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $transport->calls[0]['url']);
    }

    public function testTrackFollowsAnOfficialSend()
    {
        $transport = new RecordingTransport(self::answer(self::META));
        $sent = SentMessage::fromArray(json_decode(SentMessageTest::OFFICIAL, true));

        $status = $this->client($transport)->track($sent);

        $this->assertInstanceOf(MetaMessageStatus::class, $status);
        $this->assertSame('http://localhost/api/meta-messages/42', $transport->calls[0]['url']);
    }

    public function testTrackTakesAStoredSelfLinkAndNeverCallsItsHost()
    {
        $transport = new RecordingTransport(self::answer(self::QR), self::answer(self::META));
        $client = $this->client($transport, 'https://app.zapmizer.com/api/');

        $client->track('https://evil.test/api/sent-messages/9d3c1e2a?x=1');
        $client->track('https://app.parlichat.com/api/meta-messages/42');

        $this->assertSame('https://app.zapmizer.com/api/sent-messages/9d3c1e2a', $transport->calls[0]['url']);
        $this->assertSame('https://app.zapmizer.com/api/meta-messages/42', $transport->calls[1]['url']);
    }

    public function testTrackDecodesTheIdOfTheLink()
    {
        $transport = new RecordingTransport(self::answer(self::QR));

        $this->client($transport)->track('https://app.zapmizer.com/api/sent-messages/a%2Fb');

        $this->assertSame('http://localhost/api/sent-messages/a%2Fb', $transport->calls[0]['url']);
    }

    public function testAQueuedOfficialSendHasNothingToTrack()
    {
        $transport = new RecordingTransport();
        $sent = SentMessage::fromArray(json_decode(SentMessageTest::OFFICIAL_QUEUED, true));

        $this->assertNull($this->client($transport)->track($sent));
        $this->assertNull($this->client($transport)->track(SentMessage::fromArray(['id' => 1])));
        $this->assertSame([], $transport->calls);
    }

    /** @dataProvider linksThatAreNotTracking */
    #[DataProvider('linksThatAreNotTracking')]
    public function testALinkThatIsNotATrackingLinkIsRefused(SentMessage|string $link)
    {
        $transport = new RecordingTransport();

        try {
            $this->client($transport)->track($link);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('tracking link', $exception->getMessage());
        }

        $this->assertSame([], $transport->calls);
    }

    public static function linksThatAreNotTracking(): array
    {
        return [
            'legacy send' => [SentMessage::fromArray(json_decode(SentMessageTest::LEGACY, true))],
            'legacy link' => ['https://app.zapmizer.com/api/messages/7'],
            'not a url' => ['sent-messages/1'],
            'empty' => [''],
            'no id' => ['https://app.zapmizer.com/api/sent-messages/'],
            'nested' => ['https://app.zapmizer.com/api/sent-messages/1/extra'],
            'meta id not numeric' => ['https://app.zapmizer.com/api/meta-messages/abc'],
        ];
    }

    /** @dataProvider answersWithoutTheMinimum */
    #[DataProvider('answersWithoutTheMinimum')]
    public function testATrackingAnswerWithoutTheMinimumIsUnexpected(string $method, string $body, string $reason)
    {
        $client = $this->client(new RecordingTransport(self::answer($body)));

        try {
            $method === 'sentMessage' ? $client->sentMessage('1') : $client->metaMessage(1);
            $this->fail('Expected unexpectedResponse.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertNotInstanceOf(ZapmizerApiException::class, $exception);
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
    }

    public static function answersWithoutTheMinimum(): array
    {
        $cases = [];

        foreach (['sentMessage' => self::QR, 'metaMessage' => self::META] as $method => $body) {
            $data = json_decode($body, true)['data'];
            $cases["{$method} without data"] = [$method, json_encode($data), 'tracking answer has no `data`'];
            $cases["{$method} data not an object"] = [$method, '{"data":"x"}', 'tracking answer has no `data`'];
            $cases["{$method} without id"] = [$method, json_encode(['data' => array_diff_key($data, ['id' => 1])]), 'invalid id'];
            $cases["{$method} empty id"] = [$method, json_encode(['data' => ['id' => ''] + $data]), 'invalid id'];
            $cases["{$method} without status"] = [$method, json_encode(['data' => array_diff_key($data, ['message_status' => 1])]), 'invalid message_status'];
            $cases["{$method} status as text"] = [$method, json_encode(['data' => ['message_status' => '2'] + $data]), 'invalid message_status'];
            $cases["{$method} not an object"] = [$method, '[1]', 'response body is not a JSON object'];
        }

        return $cases;
    }

    public function testLenientFieldsFallBackToNull()
    {
        $status = QrMessageStatus::fromArray(['data' => ['id' => 5, 'message_status' => 0]]);

        $this->assertSame('5', $status->id);
        $this->assertSame(MessageStatus::SENDING, $status->status);
        $this->assertNull($status->wappMessageId);
        $this->assertNull($status->widFrom);
        $this->assertNull($status->widTo);
        $this->assertNull($status->statusName);
        $this->assertNull($status->statusLabel);
        $this->assertNull($status->timestamp);
        $this->assertNull($status->selfUrl);

        $meta = MetaMessageStatus::fromArray(['data' => ['id' => 5, 'message_status' => 1, 'bot_instance_id' => 'x', 'created_at' => 'yesterday']]);

        $this->assertNull($meta->botInstanceId);
        $this->assertNull($meta->wamid);
        $this->assertNull($meta->createdAt);
        $this->assertNull($meta->mediaUrl);
    }

    /** @dataProvider failures */
    #[DataProvider('failures')]
    public function testFailuresAreTheApiExceptions(string $method, Response|ConnectException $answer, string $class, ?int $status)
    {
        $transport = $answer instanceof Response
            ? new RecordingTransport($answer)
            : new class($answer) extends RecordingTransport {
                public function __construct(private ConnectException $failure)
                {
                }

                public function send(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
                {
                    throw ZapmizerUnavailableException::dueTo($this->failure);
                }
            };
        $client = $this->client($transport);

        try {
            $method === 'sentMessage' ? $client->sentMessage('1') : $client->metaMessage(1);
            $this->fail("Expected {$class}.");
        } catch (ZapmizerConnectException $exception) {
            $this->assertSame($class, get_class($exception));

            if ($status !== null) {
                $this->assertSame($status, $exception->status());
            }
        }
    }

    public static function failures(): array
    {
        $network = new ConnectException('timed out', new Request('GET', 'http://localhost/api/sent-messages/1'));
        $cases = [];

        foreach (['sentMessage', 'metaMessage'] as $method) {
            $cases["{$method} 401"] = [$method, self::answer('{"message":"Unauthenticated."}', 401), ZapmizerUnauthorizedException::class, 401];
            $cases["{$method} 404"] = [$method, self::answer('{"message":"Not found."}', 404), ZapmizerApiException::class, 404];
            $cases["{$method} 429"] = [$method, self::answer('{"message":"Too Many Attempts."}', 429, ['Retry-After' => '5']), ZapmizerRateLimitedException::class, 429];
            $cases["{$method} 503"] = [$method, new Response(503, [], ''), ZapmizerUnavailableException::class, null];
            $cases["{$method} network"] = [$method, $network, ZapmizerUnavailableException::class, null];
            $cases["{$method} html"] = [$method, new Response(200, ['Content-Type' => 'text/html'], '<html>login</html>'), ZapmizerConnectException::class, null];
        }

        return $cases;
    }

    public function testTheFailuresOfTheContractMatchIt()
    {
        $this->assertMatchesContract('GET', '/sent-messages/{id}', 401, '{"message":"Unauthenticated."}');
        $this->assertMatchesContract('GET', '/sent-messages/{id}', 404, '{"message":"Not found."}');
        $this->assertMatchesContract('GET', '/meta-messages/{id}', 401, '{"message":"Unauthenticated."}');
    }

    public function testTheTrackingDtosSurviveSerialization()
    {
        $qr = QrMessageStatus::fromArray(json_decode(self::QR, true));
        $meta = MetaMessageStatus::fromArray(json_decode(self::META, true));

        $this->assertEquals($qr, unserialize(serialize($qr)));
        $this->assertEquals($meta, unserialize(serialize($meta)));
    }
}
