<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\ConversationSettings;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P9: GET/PUT /bot-instances/{id}/conversation-settings —
 * `auto_transcribe_audio`, which turns on the `message_transcribed` event.
 */
class InstanceClientConversationSettingsTest extends TestCase
{
    use AssertsContract;
    use FakesZapmizerHttp;

    private const PATH = '/bot-instances/{id}/conversation-settings';

    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(Response|\Throwable ...$answers): InstanceClient
    {
        $this->fakeZapmizer(new MockHandler($answers));

        return new InstanceClient('team-token', new LaravelHttpTransport(), 'http://localhost/api', '2025-06-27');
    }

    protected function settingsCall(InstanceClient $client, string $method): ConversationSettings
    {
        return $method === 'get'
            ? $client->conversationSettings(9, '5581999998888')
            : $client->updateConversationSettings(9, '5581999998888', true);
    }

    public function testP1ReadingFromTheContract()
    {
        $body = '{"wid":"5581999998888@c.us","auto_transcribe_audio":false}';
        $this->assertMatchesContract('GET', self::PATH, 200, $body);

        $settings = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], $body))
            ->conversationSettings(9, '5581999998888');

        $this->assertInstanceOf(ConversationSettings::class, $settings);
        // The wid is the one Zapmizer answers, normalized on its side.
        $this->assertSame('5581999998888@c.us', $settings->wid);
        $this->assertFalse($settings->autoTranscribeAudio);

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('http://localhost/api/bot-instances/9/conversation-settings', (string) $request->getUri()->withQuery(''));
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['wid' => '5581999998888'], $query);
        $this->assertSame('Bearer team-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('2025-06-27', $request->getHeaderLine('api-version'));
    }

    public function testP2AGroupWidGoesAsIs()
    {
        $body = '{"wid":"120363000000000000@g.us","auto_transcribe_audio":true}';
        $this->assertMatchesContract('GET', self::PATH, 200, $body);

        $settings = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], $body))
            ->conversationSettings(9, '120363000000000000@g.us');

        $this->assertTrue($settings->autoTranscribeAudio);
        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame('120363000000000000@g.us', $query['wid']);
    }

    public function testP3UpdatingFromTheContract()
    {
        $body = '{"wid":"5581999998888@c.us","auto_transcribe_audio":true}';
        $this->assertMatchesContract('PUT', self::PATH, 200, $body);

        $settings = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], $body))
            ->updateConversationSettings(9, '5581999998888', true);

        $this->assertTrue($settings->autoTranscribeAudio);
        $this->assertSame('5581999998888@c.us', $settings->wid);

        $request = $this->history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('http://localhost/api/bot-instances/9/conversation-settings', (string) $request->getUri());
        $sent = json_decode((string) $request->getBody(), true);
        $this->assertSame(['wid' => '5581999998888', 'auto_transcribe_audio' => true], $sent);
        $this->assertMatchesSchema('#/components/schemas/UpdateConversationSettingRequest', $sent);
    }

    public function testP3TurningItOffSendsFalse()
    {
        $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], '{"wid":"5581999998888@c.us","auto_transcribe_audio":false}'))
            ->updateConversationSettings(9, '5581999998888', false);

        $this->assertSame(false, json_decode((string) $this->history[0]['request']->getBody(), true)['auto_transcribe_audio']);
    }

    /** @dataProvider invalidBodies */
    #[DataProvider('invalidBodies')]
    public function testP4AnAnswerWithoutTheFieldsIsUnexpected(string $method, string $body)
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('conversation settings');

        $this->settingsCall($this->makeClient(new Response(200, ['Content-Type' => 'application/json'], $body)), $method);
    }

    public static function invalidBodies(): array
    {
        $cases = [];

        foreach (['get', 'put'] as $method) {
            $cases += [
                "{$method}: empty object" => [$method, '{}'],
                "{$method}: without auto_transcribe_audio" => [$method, '{"wid":"5581999998888@c.us"}'],
                "{$method}: auto_transcribe_audio as text" => [$method, '{"wid":"5581999998888@c.us","auto_transcribe_audio":"true"}'],
                "{$method}: without wid" => [$method, '{"auto_transcribe_audio":true}'],
            ];
        }

        return $cases;
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testP5RefusalsFromTheContract(string $method, int $status, string $body, string $exception)
    {
        $this->assertMatchesContract($method, self::PATH, $status, $body);

        try {
            $this->settingsCall($this->makeClient(new Response($status, ['Content-Type' => 'application/json'], $body)), strtolower($method));
            $this->fail('no exception');
        } catch (ZapmizerConnectException $e) {
            $this->assertSame($exception, get_class($e));
            $this->assertSame($status, $e->status());

            if ($status === 422) {
                $this->assertSame(['O campo wid não é uma conversa válida.'], $e->errors()['wid']);
            }
        }
    }

    public static function refusalsFromTheContract(): array
    {
        $cases = [];

        foreach (['GET', 'PUT'] as $method) {
            $cases += [
                "{$method} 401" => [$method, 401, '{"message":"Unauthenticated."}', ZapmizerUnauthorizedException::class],
                "{$method} 404: gone or not this connection's" => [$method, 404, '{"message":"Não encontrado."}', InstanceGoneException::class],
                "{$method} 422" => [$method, 422, '{"message":"O campo wid não é uma conversa válida.","errors":{"wid":["O campo wid não é uma conversa válida."]}}', ZapmizerApiException::class],
            ];
        }

        return $cases;
    }

    /**
     * The connect token carries `messages:read` and `messages:create`
     * (`TokenAbility::grantedByConnect()` on Zapmizer), so it never gets
     * this 403: only a token without those abilities does.
     *
     * @dataProvider refusalsOutsideTheContract
     */
    #[DataProvider('refusalsOutsideTheContract')]
    public function testP6RefusalsOutsideTheContract(string $method, Response|\Throwable $answer, string $exception, ?string $error = null)
    {
        try {
            $this->settingsCall($this->makeClient($answer), $method);
            $this->fail('no exception');
        } catch (ZapmizerConnectException $e) {
            $this->assertSame($exception, get_class($e));

            if ($error !== null) {
                $this->assertSame($error, $e->error());
                $this->assertSame(403, $e->status());
            }
        }
    }

    public static function refusalsOutsideTheContract(): array
    {
        $json = ['Content-Type' => 'application/json'];
        $cases = [];

        foreach (['get', 'put'] as $method) {
            $cases += [
                "{$method}: 403 missing_ability" => [$method, new Response(403, $json, '{"message":"Invalid ability provided.","error":"missing_ability"}'), ZapmizerApiException::class, ErrorCode::MISSING_ABILITY],
                "{$method}: 429" => [$method, new Response(429, $json + ['Retry-After' => '5'], '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class],
                "{$method}: 500" => [$method, new Response(500, [], ''), ZapmizerUnavailableException::class],
                "{$method}: no answer" => [$method, new ConnectException('timeout', new Request('GET', 'http://localhost/api/x')), ZapmizerUnavailableException::class],
                "{$method}: redirect to the login page" => [$method, new Response(302, ['Location' => 'http://localhost/login'], ''), ZapmizerConnectException::class],
                "{$method}: html instead of json" => [$method, new Response(200, ['Content-Type' => 'text/html'], '<html></html>'), ZapmizerConnectException::class],
            ];
        }

        return $cases;
    }
}
