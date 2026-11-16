<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Messages\LegacyMessageTranslator;
use NotificationChannels\Zapmizer\Messages\Message;
use NotificationChannels\Zapmizer\Messages\MessageType;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The translation of WhatsApp Web's message against Zapmizer's own, on
 * Zapmizer's fixtures (`tests/contract/message-contract`, copied from
 * `bot-v2/tests/fixtures/message-contract` by the `update.php` next to
 * them, which records the commit in `SOURCE`). A `wwebjs` fixture has `raw`
 * (the whatsapp-web.js message: the `data[0]` of the `message` webhook) and
 * `message` (what `fromWwebjs()` reads of it); the contract is
 * `toPartnerMessage(message)` of `bot-v2/src/messaging/partner.ts`, written
 * here as `partnerMessage()`. A `whatsapp_rust` fixture has the Rust
 * engine's `message` and `legacy_payload`, the whatsapp-web.js imitation the
 * `message` webhook gets from that engine (`to-wwebjs.ts`).
 */
class LegacyMessageParityTest extends TestCase
{
    use AssertsContract;

    /**
     * Fields where the package knowingly differs from Zapmizer on a `wwebjs`
     * fixture: `fixture => [field => why]`. Empty: the translation matches
     * every fixture field by field. A new divergence goes here, with the
     * reason, never into a looser comparison.
     */
    public const ACCEPTED_DIVERGENCES = [];

    /**
     * The same, on the `legacy_payload` of a `whatsapp_rust` fixture.
     */
    public const ACCEPTED_RUST_DIVERGENCES = [
        'button-reply' => ['reply' => 'The fixture\'s legacy_payload is a cut of the imitation (Zapmizer checks it with toMatchObject): it has no selectedButtonId, which projectToWwebjs() sends (to-wwebjs.ts:51), so the reply id reads null.'],
        'list-reply' => ['reply' => 'The fixture\'s legacy_payload has no selectedRowId nor listResponse, which projectToWwebjs() sends (to-wwebjs.ts:52, 64-67), so the reply id and description read null.'],
        'text-mentions' => ['mentions' => 'The fixture\'s legacy_payload has no mentionedIds, which projectToWwebjs() sends (to-wwebjs.ts:47), so mentions read [].'],
    ];

    private const DATA_SCHEMA = '#/webhooks/message_received/post/requestBody/content/application~1json/schema/properties/data';

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtures(): array
    {
        $cases = [];

        foreach (glob(self::directory() . '/*.json') ?: [] as $path) {
            $cases[basename($path, '.json')] = [basename($path, '.json')];
        }

        return $cases;
    }

    /** @dataProvider fixtures */
    #[DataProvider('fixtures')]
    public function testTheTranslationIsZapmizers(string $name)
    {
        $fixture = self::fixture($name);
        $expected = self::partnerMessage($fixture['message'], $fixture['raw']);
        $actual = LegacyMessageTranslator::toContract($fixture['raw']);

        foreach (array_keys(self::ACCEPTED_DIVERGENCES[$name] ?? []) as $field) {
            unset($expected[$field], $actual[$field]);
        }

        $this->assertSame(self::sorted($expected), self::sorted($actual));
    }

    /** @dataProvider fixtures */
    #[DataProvider('fixtures')]
    public function testTheMessageIsTheOneOfTheContract(string $name)
    {
        $fixture = self::fixture($name);
        $contract = self::partnerMessage($fixture['message'], $fixture['raw']);

        $this->assertMatchesSchema(self::DATA_SCHEMA, $contract);
        $this->assertMatchesSchema(self::DATA_SCHEMA, LegacyMessageTranslator::toContract($fixture['raw']));
        $this->assertEquals(Message::fromContract($contract), Message::fromLegacy($fixture['raw']));
    }

    public function testEveryAcceptedDivergenceStillDiverges()
    {
        foreach (self::ACCEPTED_DIVERGENCES as $name => $fields) {
            $fixture = self::fixture($name);
            $expected = self::partnerMessage($fixture['message'], $fixture['raw']);
            $actual = LegacyMessageTranslator::toContract($fixture['raw']);

            foreach ($fields as $field => $why) {
                $this->assertNotSame('', trim($why), "{$name}.{$field} needs a reason.");
                $this->assertNotSame($expected[$field] ?? null, $actual[$field] ?? null, "{$name}.{$field} no longer diverges: take it off the list.");
            }
        }

        $this->assertSame([], self::ACCEPTED_DIVERGENCES);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rustFixtures(): array
    {
        $cases = [];

        foreach (glob(self::directory('whatsapp_rust') . '/*.json') ?: [] as $path) {
            $cases[basename($path, '.json')] = [basename($path, '.json')];
        }

        return $cases;
    }

    /** @dataProvider rustFixtures */
    #[DataProvider('rustFixtures')]
    public function testTheRustEnginesLegacyPayloadReadsTheSame(string $name)
    {
        $fixture = self::fixture($name, 'whatsapp_rust');
        $this->assertArrayHasKey('legacy_payload', $fixture);
        $expected = self::partnerMessage($fixture['message'], $fixture['legacy_payload']);
        $actual = LegacyMessageTranslator::toContract($fixture['legacy_payload']);

        foreach (array_keys(self::ACCEPTED_RUST_DIVERGENCES[$name] ?? []) as $field) {
            $this->assertNotSame(self::sorted($expected[$field]), self::sorted($actual[$field]), "{$name}.{$field} no longer diverges: take it off the list.");
            unset($expected[$field], $actual[$field]);
        }

        $this->assertSame(self::sorted($expected), self::sorted($actual));
    }

    public function testTheFixturesCoverEveryType()
    {
        $types = array_unique(array_map(
            fn (array $case) => self::fixture($case[0])['message']['type'],
            self::fixtures(),
        ));
        sort($types);
        $all = MessageType::ALL;
        sort($all);

        $this->assertSame($all, $types);
    }

    public function testTheFixturesComeFromARecordedCommit()
    {
        $this->assertMatchesRegularExpression('/^commit: [0-9a-f]{40} /m', (string) file_get_contents(dirname(self::directory()) . '/SOURCE'));
        $this->assertGreaterThanOrEqual(30, count(self::fixtures()));
    }

    /**
     * `emitsMessageEvent()`: no notice reaches the webhook.
     * `syncsMessage()`: the sync leaves out only `system`.
     */
    public function testTheNoticesFollowZapmizersRules()
    {
        $callLog = self::fixture('unknown-call-log')['raw'];
        $ciphertext = self::fixture('unknown-ciphertext')['raw'];
        $album = self::fixture('unknown-album')['raw'];

        $this->assertSame(LegacyMessageTranslator::NOTICE_SYSTEM, LegacyMessageTranslator::notice($callLog));
        $this->assertNull(LegacyMessageTranslator::forEvent($callLog));
        $this->assertNull(LegacyMessageTranslator::forSync($callLog));

        $this->assertSame(LegacyMessageTranslator::NOTICE_PENDING, LegacyMessageTranslator::notice($ciphertext));
        $this->assertNull(LegacyMessageTranslator::forEvent($ciphertext));
        $synced = LegacyMessageTranslator::forSync($ciphertext);
        $this->assertSame(MessageType::UNKNOWN, $synced->type);
        $this->assertSame($ciphertext, $synced->raw);

        $this->assertNull(LegacyMessageTranslator::notice($album));
        $this->assertSame(MessageType::UNKNOWN, LegacyMessageTranslator::forEvent($album)->type);
        $this->assertSame($album, LegacyMessageTranslator::forEvent($album)->raw);
        $this->assertSame($album, LegacyMessageTranslator::forSync($album)->raw);
    }

    /**
     * Each notice of `WHATSAPP_WEB_NOTICES`, on the text fixture.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function notices(): array
    {
        $cases = [];

        foreach (LegacyMessageTranslator::NOTICES as $type => $notice) {
            $cases[$type] = [$type, $notice];
        }

        return $cases;
    }

    /** @dataProvider notices */
    #[DataProvider('notices')]
    public function testEachNoticeIsKnown(string $type, string $notice)
    {
        $raw = ['type' => $type, '_data' => ['type' => $type]] + self::fixture('text')['raw'];

        $this->assertSame($notice, LegacyMessageTranslator::notice($raw));
        $this->assertNull(LegacyMessageTranslator::forEvent($raw));
        $this->assertSame($notice === LegacyMessageTranslator::NOTICE_SYSTEM, LegacyMessageTranslator::forSync($raw) === null);
    }

    /**
     * Zapmizer's own cases of `from-wwebjs.spec.ts`.
     */
    public function testZapmizersEdgeCases()
    {
        $expired = LegacyMessageTranslator::toContract([
            'id' => ['id' => '3EB0EXPIRED', 'fromMe' => false, 'remote' => '5511911111111@c.us'],
            'from' => '5511911111111@c.us', 'to' => '5511900000000@c.us', 'fromMe' => false, 'type' => 'image',
            'hasMedia' => false, 'body' => '/9j/4AAQSkZJRgABAQAAAQABAAD', 'timestamp' => 1760000000,
            '_data' => ['body' => '/9j/4AAQSkZJRgABAQAAAQABAAD', 'mimetype' => 'image/jpeg'],
        ]);
        $this->assertSame('image', $expired['type']);
        $this->assertNull($expired['text']);

        $quoting = fn ($participant) => LegacyMessageTranslator::toContract([
            'id' => ['id' => '3EB0REPLY', 'fromMe' => false, 'remote' => '5511911111111@c.us'],
            'from' => '5511911111111@c.us', 'to' => '5511900000000@c.us', 'fromMe' => false, 'type' => 'chat',
            'body' => 'isso', 'timestamp' => 1760000000, 'hasQuotedMsg' => true,
            '_data' => ['quotedStanzaID' => '3EB0ORIGINAL', 'quotedParticipant' => $participant, 'quotedMsg' => ['type' => 'chat', 'body' => 'pergunta']],
        ])['quoted'];
        $this->assertSame('5511900000000@c.us', $quoting(['server' => 'c.us', 'user' => '5511900000000', '_serialized' => '5511900000000@c.us'])['sender']);
        $this->assertSame($quoting(['server' => 'c.us', 'user' => '5511900000000']), $quoting('5511900000000@c.us'));

        $toGroup = Message::fromLegacy([
            'id' => ['id' => '3EB0GROUPSENT', 'fromMe' => true, 'remote' => '120363000000000001@g.us'],
            'from' => '5511900000000@c.us', 'to' => '120363000000000001@g.us', 'fromMe' => true, 'type' => 'chat',
            'body' => 'oi, grupo', 'timestamp' => 1760000000, 'deviceType' => 'web', '_data' => [],
        ]);
        $this->assertSame('120363000000000001@g.us', $toGroup->chat);
        $this->assertSame('5511900000000@c.us', $toGroup->sender);
        $this->assertTrue($toGroup->fromMe);
        $this->assertFalse($toGroup->fromMeOtherDevice);

        $poisoned = [
            'id' => ['id' => '3EB0POISON', 'fromMe' => true, 'remote' => '5511911111111@c.us'],
            'from' => '5511900000000@c.us', 'to' => '5511911111111@c.us', 'fromMe' => true, 'type' => 'vcard',
            'timestamp' => 1760000000, '_data' => ['vcardList' => [null]],
        ];
        $unknown = Message::fromLegacy($poisoned);
        $this->assertSame(MessageType::UNKNOWN, $unknown->type);
        $this->assertSame('3EB0POISON', $unknown->id);
        $this->assertSame('5511911111111@c.us', $unknown->chat);
        $this->assertTrue($unknown->fromMe);
        $this->assertSame(1760000000, $unknown->timestamp);
        $this->assertSame($poisoned, $unknown->raw);
    }

    public function testWhatCannotBeReadIsUnknownAndKeptWhole()
    {
        foreach ([[], ['type' => 'chat'], ['id' => 'string', 'type' => 'chat'], ['_data' => 'x']] as $raw) {
            $message = Message::fromLegacy($raw);

            $this->assertSame(MessageType::UNKNOWN, $message->type);
            $this->assertSame('', $message->id);
            $this->assertSame($raw, $message->raw);
        }

        $poll = Message::fromLegacy(['id' => ['id' => 'P'], 'from' => '5511911111111@c.us', 'type' => 'poll_creation', 'pollOptions' => 'nope']);
        $this->assertSame(MessageType::UNKNOWN, $poll->type);
        $this->assertSame('P', $poll->id);
        $this->assertSame('5511911111111@c.us', $poll->chat);
    }

    /**
     * `toPartnerMessage()` of `bot-v2/src/messaging/partner.ts`, on the
     * fixture's `message` (Zapmizer's own reading of `raw`).
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private static function partnerMessage(array $message, mixed $raw): array
    {
        $media = $message['media'];
        $quoted = $message['quoted'];
        $linkPreview = $message['link_preview'];

        return [
            'id' => $message['id'],
            'chat' => $message['chat'],
            'sender' => $message['sender'],
            'sender_name' => $message['sender_name'],
            'from_me' => $message['from_me'],
            'from_me_other_device' => $message['from_me_other_device'],
            'timestamp' => $message['timestamp'],
            'type' => $message['type'],
            'text' => $message['text'],
            'quoted' => $quoted ? ['id' => $quoted['id'], 'sender' => $quoted['sender'], 'type' => $quoted['type'], 'text' => $quoted['text']] : null,
            'forwarded' => $message['forwarded'],
            'view_once' => $message['view_once'],
            'mentions' => $message['mentions'],
            'media' => $media ? [
                'mime' => $media['mime'],
                'filename' => $media['filename'],
                'size' => $media['size'],
                'duration' => $media['duration'],
                'width' => $media['width'],
                'height' => $media['height'],
                'page_count' => $media['page_count'],
                'is_gif' => $media['is_gif'],
            ] : null,
            'location' => $message['location'],
            'contacts' => $message['contacts'],
            'poll' => $message['poll'],
            'reply' => $message['reply'],
            'link_preview' => $linkPreview ? ['url' => $linkPreview['url'], 'title' => $linkPreview['title'], 'description' => $linkPreview['description']] : null,
        ] + ($message['type'] === 'unknown' ? ['raw' => $raw] : []);
    }

    /**
     * @return array{raw: array<string, mixed>, message: array<string, mixed>}
     */
    private static function fixture(string $name, string $engine = 'wwebjs'): array
    {
        return json_decode((string) file_get_contents(self::directory($engine) . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function directory(string $engine = 'wwebjs'): string
    {
        return dirname(__DIR__) . '/contract/message-contract/' . $engine;
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => self::sorted($item), $value);
    }
}
