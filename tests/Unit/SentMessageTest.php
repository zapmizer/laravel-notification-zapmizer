<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Messages\MessageStatus;
use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;

class SentMessageTest extends TestCase
{
    use AssertsContract;

    public const QR = '{"id":"9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a","type":"chat","wid_from":"5581999990000@c.us","wid_to":"551199999999@c.us","message_status":-2,"message_status_name":"Enviando","metadata":{"text":"hi"},"attachment_url":null,"links":{"self":"https://app.zapmizer.com/api/sent-messages/9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a"}}';

    public const OFFICIAL = '{"id":42,"type":"chat","wid_from":"5581999990000@c.us","wid_to":"5511999999999@c.us","message_status":-2,"message_status_name":"Enviando","metadata":{"text":"hi"},"attachment_url":null,"links":{"self":"https://app.zapmizer.com/api/meta-messages/42"}}';

    public const OFFICIAL_QUEUED = '{"id":null,"type":"chat","wid_from":"5581999990000@c.us","wid_to":"5511999999999@c.us","message_status":-2,"message_status_name":"Enviando","metadata":{"text":"hi"},"attachment_url":null,"links":{"self":null}}';

    public const LEGACY = '{"data":{"id":7,"type":"chat","wid_from":"5581999990000@c.us","wid_to":"551199999999@c.us","author_name":"API","wapp_message_id":"","message_status":-2,"message_status_name":"ENQUEUED","message_status_label":"Enviando","error":null,"error_message":null,"metadata":[],"attachment_url":"","links":{"self":"https://app.zapmizer.com/api/messages/7"}}}';

    protected function sent(string $body): SentMessage
    {
        return SentMessage::fromArray(json_decode($body, true));
    }

    public function testTheQrAnswerOfTheContract()
    {
        $this->assertMatchesContract('POST', '/messages', 200, self::QR);

        $sent = $this->sent(self::QR);

        $this->assertSame('9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $sent->id);
        $this->assertSame('https://app.zapmizer.com/api/sent-messages/9d3c1e2a-5b7f-4c1e-9a8b-1f2e3d4c5b6a', $sent->selfUrl);
        $this->assertSame('5581999990000@c.us', $sent->widFrom);
        $this->assertSame('551199999999@c.us', $sent->widTo);
        $this->assertSame(MessageStatus::QUEUED, $sent->status);
        $this->assertSame('Enviando', $sent->statusName);
        $this->assertSame('chat', $sent->type);
        $this->assertTrue($sent->isTrackable());
        $this->assertSame(json_decode(self::QR, true), $sent->raw());
    }

    public function testTheOfficialIdBecomesAString()
    {
        $this->assertMatchesContract('POST', '/messages', 200, self::OFFICIAL);

        $sent = $this->sent(self::OFFICIAL);

        $this->assertSame('42', $sent->id);
        $this->assertSame('https://app.zapmizer.com/api/meta-messages/42', $sent->selfUrl);
        $this->assertTrue($sent->isTrackable());
    }

    public function testAQueuedOfficialSendHasNothingToTrack()
    {
        $this->assertMatchesContract('POST', '/messages', 200, self::OFFICIAL_QUEUED);

        $sent = $this->sent(self::OFFICIAL_QUEUED);

        $this->assertNull($sent->id);
        $this->assertNull($sent->selfUrl);
        $this->assertSame('5511999999999@c.us', $sent->widTo);
        $this->assertFalse($sent->isTrackable());
    }

    public function testTheLegacyShapeIsReadFromData()
    {
        $this->assertMatchesContract('GET', '/messages/{id}', 200, self::LEGACY);

        $sent = $this->sent(self::LEGACY);

        $this->assertSame('7', $sent->id);
        $this->assertSame('https://app.zapmizer.com/api/messages/7', $sent->selfUrl);
        $this->assertSame('551199999999@c.us', $sent->widTo);
        $this->assertSame(MessageStatus::QUEUED, $sent->status);
        $this->assertSame(json_decode(self::LEGACY, true), $sent->raw());
    }

    public function testAnAnswerWithoutFieldsIsAnEmptySend()
    {
        $this->assertMatchesContract('POST', '/messages', 200, '{}');

        $sent = $this->sent('{}');

        $this->assertNull($sent->id);
        $this->assertNull($sent->selfUrl);
        $this->assertNull($sent->widFrom);
        $this->assertNull($sent->widTo);
        $this->assertNull($sent->status);
        $this->assertNull($sent->statusName);
        $this->assertNull($sent->type);
        $this->assertFalse($sent->isTrackable());
        $this->assertSame([], $sent->raw());
    }

    public function testAnIdWithoutSelfLinkIsNotTrackable()
    {
        $this->assertMatchesContract('POST', '/messages', 200, '{"id": 1}');

        $sent = $this->sent('{"id": 1}');

        $this->assertSame('1', $sent->id);
        $this->assertFalse($sent->isTrackable());
    }

    public function testASelfLinkThatIsNotAnHttpUrlIsDropped()
    {
        foreach (['/api/sent-messages/1', 'javascript:alert(1)', 'https://user:pass@app.zapmizer.com/api/sent-messages/1', 12] as $self) {
            $this->assertNull(SentMessage::fromArray(['id' => 1, 'links' => ['self' => $self]])->selfUrl, var_export($self, true));
        }
    }

    public function testAStatusThatIsNotAnIntegerIsDropped()
    {
        $this->assertNull(SentMessage::fromArray(['message_status' => '-2'])->status);
        $this->assertNull(SentMessage::fromArray(['message_status' => null])->status);
    }

    public function testAnEmptyIdIsNoId()
    {
        $this->assertNull(SentMessage::fromArray(['id' => ''])->id);
        $this->assertNull(SentMessage::fromArray(['id' => ['x']])->id);
        $this->assertNull(SentMessage::fromArray(['id' => true])->id);
    }

    public function testTheStatusShortcutsFollowTheScale()
    {
        $this->assertFalse($this->sent(self::QR)->isFailed());
        $this->assertFalse($this->sent(self::QR)->isDelivered());
        $this->assertFalse($this->sent(self::QR)->isFinal());
        $this->assertFalse($this->sent('{}')->isFailed());
        $this->assertFalse($this->sent('{}')->isFinal());
    }

    public function testItSurvivesSerialization()
    {
        $sent = $this->sent(self::QR);

        $this->assertEquals($sent, unserialize(serialize($sent)));
    }
}
