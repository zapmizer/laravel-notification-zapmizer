<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Test\Fixtures\SignsWebhookDeliveries;
use NotificationChannels\Zapmizer\Test\TestCase;

class InboundMessageTest extends TestCase
{
    use SignsWebhookDeliveries;

    public function testIndividualMessage()
    {
        $message = InboundMessage::fromEnvelope(
            $this->messageEnvelope('5581999998888@c.us', ['id' => 'ABC', 'body' => 'hello', 'timestamp' => 1700000000]),
            '5581911110000@c.us',
        );

        $this->assertInstanceOf(InboundMessage::class, $message);
        $this->assertEquals('false_5581999998888@c.us_ABC', $message->id);
        $this->assertEquals('5581999998888@c.us', $message->from);
        $this->assertEquals('5581999998888', $message->fromPhone);
        $this->assertEquals('5581911110000@c.us', $message->to);
        $this->assertEquals('hello', $message->body);
        $this->assertEquals('chat', $message->type);
        $this->assertFalse($message->hasMedia);
        $this->assertNull($message->mediaMetadata);
        $this->assertFalse($message->isGroup);
        $this->assertFalse($message->fromMe);
        $this->assertFalse($message->hasUnresolvedSender);
        $this->assertEquals(1700000000, $message->sentAt->getTimestamp());
    }

    public function testToFallsBackToTheMessageWhenNoWidIsGiven()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope());

        $this->assertEquals('5581911110000@c.us', $message->to);
    }

    public function testGroupMessage()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope('120363000000000000@g.us', [
            'author' => '5581999998888@c.us',
        ]));

        $this->assertTrue($message->isGroup);
        // `from` is the group — the chat; `author` is who wrote in it.
        $this->assertEquals('120363000000000000@g.us', $message->from);
        $this->assertEquals('120363000000000000', $message->fromPhone);
        $this->assertEquals('5581999998888@c.us', $message->author);
        $this->assertEquals('5581999998888', $message->authorPhone);
        $this->assertFalse($message->hasUnresolvedSender);
    }

    public function testGroupAuthorResolvedThroughData()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope('120363000000000000@g.us', [
            '_data' => ['author' => ['_serialized' => '5581977776666@c.us']],
        ]));

        $this->assertEquals('5581977776666@c.us', $message->author);
        $this->assertEquals('5581977776666', $message->authorPhone);
    }

    public function testGroupAuthorAsLidIsFlaggedUnresolved()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope('120363000000000000@g.us', [
            'author' => '84474155032797@lid',
        ]));

        $this->assertTrue($message->isGroup);
        $this->assertEquals('84474155032797@lid', $message->author);
        $this->assertEquals('', $message->authorPhone);
        $this->assertTrue($message->hasUnresolvedSender);
    }

    public function testAuthorIsNullOutsideGroups()
    {
        $this->assertNull(InboundMessage::fromEnvelope($this->messageEnvelope())->author);
        $this->assertEquals('', InboundMessage::fromEnvelope($this->messageEnvelope())->authorPhone);
    }

    public function testFromMeIsCarriedButNeverExpected()
    {
        // whatsapp-web.js does not emit `message` for the bot's own sends;
        // the flag is only mirrored from the payload.
        $this->assertFalse(InboundMessage::fromEnvelope($this->messageEnvelope())->fromMe);
        $this->assertTrue(InboundMessage::fromEnvelope($this->messageEnvelope(overrides: ['fromMe' => true]))->fromMe);
    }

    public function testStatusBroadcastIsFlagged()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope('status@broadcast'));

        $this->assertTrue($message->isBroadcast);
        $this->assertFalse($message->isGroup);
        $this->assertEquals('', $message->fromPhone);

        $this->assertFalse(InboundMessage::fromEnvelope($this->messageEnvelope())->isBroadcast);
    }

    public function testUnresolvedLidSender()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope('84474155032797@lid'));

        $this->assertTrue($message->hasUnresolvedSender);
        $this->assertEquals('84474155032797@lid', $message->from);
    }

    public function testLidSenderResolvedThroughDataFrom()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope('84474155032797@lid', [
            '_data' => ['from' => ['_serialized' => '5581999998888@c.us']],
        ]));

        $this->assertFalse($message->hasUnresolvedSender);
        $this->assertEquals('5581999998888@c.us', $message->from);
        $this->assertEquals('5581999998888', $message->fromPhone);
    }

    public function testLidSenderResolvedThroughAuthor()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope('84474155032797@lid', [
            'author' => '5581977776666@c.us',
        ]));

        $this->assertEquals('5581977776666@c.us', $message->from);
    }

    public function testMediaMetadataIsKeptWithoutTheBytes()
    {
        $message = InboundMessage::fromEnvelope($this->messageEnvelope(overrides: [
            'type' => 'document',
            'hasMedia' => true,
            '_data' => ['mimetype' => 'application/pdf', 'filename' => 'statement.pdf', 'size' => 1234, 'caption' => null],
        ]));

        $this->assertTrue($message->hasMedia);
        $this->assertEquals('document', $message->type);
        $this->assertEquals(
            ['mimetype' => 'application/pdf', 'filename' => 'statement.pdf', 'size' => 1234],
            $message->mediaMetadata
        );
        $this->assertEquals('statement.pdf', $message->mediaFilename());
        $this->assertEquals('application/pdf', $message->mediaMimeType());

        $text = InboundMessage::fromEnvelope($this->messageEnvelope());
        $this->assertNull($text->mediaFilename());
        $this->assertNull($text->mediaMimeType());
    }

    public function testNonMessageEventsAndMalformedDataAreNull()
    {
        $this->assertNull(InboundMessage::fromEnvelope(['name' => 'qr', 'data' => [['qr' => 'x']]]));
        $this->assertNull(InboundMessage::fromEnvelope(['name' => 'message', 'data' => 'nope']));
        $this->assertNull(InboundMessage::fromEnvelope(['name' => 'message', 'data' => [['from' => 'x@c.us']]]));
    }
}
