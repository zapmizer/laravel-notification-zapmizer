<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Messages\LinkPreview;
use NotificationChannels\Zapmizer\Messages\Message;
use NotificationChannels\Zapmizer\Messages\MessageLocation;
use NotificationChannels\Zapmizer\Messages\MessageMedia;
use NotificationChannels\Zapmizer\Messages\MessagePoll;
use NotificationChannels\Zapmizer\Messages\MessageReply;
use NotificationChannels\Zapmizer\Messages\MessageType;
use NotificationChannels\Zapmizer\Messages\QuotedMessage;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MessageTest extends TestCase
{
    use AssertsContract;

    /**
     * Every example of `message_received` and `message_sent` in the contract.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function contractExamples(): array
    {
        $cases = [];

        foreach (['message_received', 'message_sent'] as $event) {
            $examples = self::contractDocument()->webhooks->{$event}->post->requestBody->content->{'application/json'}->examples;

            foreach ($examples as $name => $example) {
                $cases["{$event} {$name}"] = [$event, json_decode(json_encode($example->value), true)];
            }
        }

        return $cases;
    }

    /** @dataProvider contractExamples */
    #[DataProvider('contractExamples')]
    public function testEachExampleOfTheContractIsRead(string $event, array $envelope)
    {
        $data = $envelope['data'];
        $message = Message::fromContract($data);

        $this->assertSame($data['id'], $message->id);
        $this->assertSame($data['chat'], $message->chat);
        $this->assertSame($data['sender'], $message->sender);
        $this->assertSame($data['sender_name'], $message->senderName);
        $this->assertSame($data['from_me'], $message->fromMe);
        $this->assertSame($event === 'message_sent', $message->fromMe);
        $this->assertSame($data['from_me_other_device'], $message->fromMeOtherDevice);
        $this->assertSame($data['timestamp'], $message->timestamp);
        $this->assertContains($message->type, MessageType::ALL);
        $this->assertSame($data['type'], $message->type);
        $this->assertSame($data['text'], $message->text);
        $this->assertSame($data['forwarded'], $message->forwarded);
        $this->assertSame($data['view_once'], $message->viewOnce);
        $this->assertSame($data['mentions'], $message->mentions);
        $this->assertSame($data['media'] !== null, $message->hasMedia());
        $this->assertSame($data['media']['mime'] ?? null, $message->media?->mime);
        $this->assertSame($data['media']['filename'] ?? null, $message->media?->filename);
        $this->assertSame($data['quoted']['id'] ?? null, $message->quoted?->id);
        $this->assertSame($data['location'] === null, $message->location === null);
        $this->assertSame($data['contacts'], $message->contacts);
        $this->assertSame($data['poll']['options'] ?? null, $message->poll?->options);
        $this->assertSame($data['reply']['label'] ?? null, $message->reply?->label);
        $this->assertSame($data['link_preview']['url'] ?? null, $message->linkPreview?->url);
        $this->assertSame($data['raw'] ?? null, $message->raw);
    }

    public function testEveryFieldOfTheContractIsRead()
    {
        $data = [
            'id' => '3EB0C4A1B2D3E4F5A6B7',
            'chat' => '5511988887777@c.us',
            'sender' => '5511988887777@c.us',
            'sender_name' => 'Maria Souza',
            'from_me' => false,
            'from_me_other_device' => false,
            'timestamp' => 1760000000,
            'type' => 'document',
            'text' => 'segue',
            'quoted' => ['id' => '3EB0A1', 'sender' => '5511900000000@c.us', 'type' => 'text', 'text' => 'Posso ajudar?'],
            'forwarded' => true,
            'view_once' => false,
            'mentions' => ['5511922222222@c.us'],
            'media' => ['mime' => 'application/pdf', 'filename' => 'extrato.pdf', 'size' => 555593, 'duration' => 7.5, 'width' => 1, 'height' => 2, 'page_count' => 3, 'is_gif' => false],
            'location' => ['latitude' => -23.5505, 'longitude' => -46, 'name' => 'Escritório', 'address' => 'Rua Exemplo, 100', 'url' => 'https://maps.test'],
            'contacts' => [['name' => 'Ana', 'vcard' => "BEGIN:VCARD\nFN:Ana\nEND:VCARD"]],
            'poll' => ['name' => 'Quando?', 'options' => ['Hoje', 'Amanhã'], 'multiple' => true],
            'reply' => ['id' => 'row_2', 'label' => 'Segunda-feira', 'description' => '09:00'],
            'link_preview' => ['url' => 'https://parli.test', 'title' => 'Parli', 'description' => 'WhatsApp'],
        ];
        $this->assertMatchesSchema('#/webhooks/message_received/post/requestBody/content/application~1json/schema/properties/data', $data);

        $message = Message::fromContract($data);

        $this->assertEquals(new QuotedMessage('3EB0A1', '5511900000000@c.us', 'text', 'Posso ajudar?'), $message->quoted);
        $this->assertEquals(new MessageMedia('application/pdf', 'extrato.pdf', 555593, 7.5, 1, 2, 3, false), $message->media);
        $this->assertEquals(new MessageLocation(-23.5505, -46.0, 'Escritório', 'Rua Exemplo, 100', 'https://maps.test'), $message->location);
        $this->assertSame([['name' => 'Ana', 'vcard' => "BEGIN:VCARD\nFN:Ana\nEND:VCARD"]], $message->contacts);
        $this->assertEquals(new MessagePoll('Quando?', ['Hoje', 'Amanhã'], true), $message->poll);
        $this->assertEquals(new MessageReply('row_2', 'Segunda-feira', '09:00'), $message->reply);
        $this->assertEquals(new LinkPreview('https://parli.test', 'Parli', 'WhatsApp'), $message->linkPreview);
        $this->assertTrue($message->forwarded);
        $this->assertSame(['5511922222222@c.us'], $message->mentions);
        $this->assertNull($message->raw);
    }

    public function testItIsTolerant()
    {
        $message = Message::fromContract(['id' => 'X', 'new_field' => 1, 'from_me' => 'yes', 'timestamp' => '1760000000', 'mentions' => ['a@c.us', 3], 'media' => 'none', 'quoted' => ['sender' => 's']]);

        $this->assertSame('X', $message->id);
        $this->assertSame('', $message->chat);
        $this->assertNull($message->sender);
        $this->assertFalse($message->fromMe);
        $this->assertSame(0, $message->timestamp);
        $this->assertSame(MessageType::UNKNOWN, $message->type);
        $this->assertSame(['a@c.us'], $message->mentions);
        $this->assertNull($message->media);
        $this->assertNull($message->quoted);
        $this->assertFalse($message->hasMedia());

        $this->assertNull(Message::fromContract([]));
        $this->assertNull(Message::fromContract(['id' => 42]));
        $this->assertSame('', Message::fromContract(['id' => ''])->id);
    }

    public function testATypeAddedLaterIsKeptAsItCame()
    {
        $this->assertSame('reaction', Message::fromContract(['id' => 'X', 'type' => 'reaction'])->type);
    }

    public function testUnknownKeepsTheOriginal()
    {
        $raw = ['type' => 'album', 'id' => ['id' => 'A']];

        $message = Message::fromContract(['id' => 'A', 'type' => 'unknown', 'raw' => $raw]);

        $this->assertSame(MessageType::UNKNOWN, $message->type);
        $this->assertSame($raw, $message->raw);
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: bool, 3: bool}>
     */
    public static function chats(): array
    {
        return [
            'contact' => ['5511988887777@c.us', '5511988887777', false, false],
            'group' => ['120363000000000001@g.us', null, true, false],
            'lid' => ['100000000000001@lid', null, false, false],
            'status' => ['status@broadcast', null, false, true],
            'channel' => ['120363000000000002@newsletter', null, false, false],
            'empty' => ['', null, false, false],
        ];
    }

    /** @dataProvider chats */
    #[DataProvider('chats')]
    public function testTheChatTellsTheContactsNumber(string $chat, ?string $phone, bool $group, bool $broadcast)
    {
        $message = Message::fromContract(['id' => 'X', 'chat' => $chat, 'sender' => $chat]);

        $this->assertSame($phone, $message->chatPhone());
        $this->assertSame($phone, $message->senderPhone());
        $this->assertSame($group, $message->isGroup());
        $this->assertSame($broadcast, $message->isBroadcast());
    }

    public function testInAGroupTheSenderIsTheParticipant()
    {
        $message = Message::fromContract(['id' => 'X', 'chat' => '120363000000000001@g.us', 'sender' => '5511922222222@c.us']);

        $this->assertNull($message->chatPhone());
        $this->assertSame('5511922222222', $message->senderPhone());
        $this->assertNull(Message::fromContract(['id' => 'X'])->senderPhone());
    }

    public function testSentAtIsTheTimestamp()
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        date_default_timezone_set('America/Sao_Paulo');

        try {
            $sentAt = Message::fromContract(['id' => 'X', 'timestamp' => 1760000000])->sentAt();

            $this->assertSame(1760000000, $sentAt->getTimestamp());
            $this->assertSame('America/Sao_Paulo', $sentAt->getTimezone()->getName());
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function testTheTypesAreTheContracts()
    {
        $enum = self::contractDocument()->webhooks->message_received->post->requestBody->content->{'application/json'}->schema->properties->data->properties->type->enum;

        $this->assertSame($enum, MessageType::ALL);
    }
}
