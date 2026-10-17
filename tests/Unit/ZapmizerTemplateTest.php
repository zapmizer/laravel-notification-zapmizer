<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Messages\SentTemplate;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Zapmizer;
use PHPUnit\Framework\Attributes\DataProvider;

class ZapmizerTemplateTest extends TestCase
{
    use AssertsContract;
    use FakesZapmizerHttp;

    public const SENT = '{"wamid":"wamid.HBgMNTUxMTk5OTk5OTk5ORUCABEYEjA","wid_from":"5581999990000@c.us","wid_to":"5511999999999@c.us","preview_text":"Olá, Ana! Seu pedido 123 saiu para entrega."}';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, options: array}> */
    protected array $history = [];

    protected function client(Response ...$responses): Zapmizer
    {
        $this->fakeZapmizer(new MockHandler($responses));

        return new Zapmizer('bot-token', new LaravelHttpTransport(), 'http://localhost/api', '2025-06-27');
    }

    protected static function answer(string $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $body);
    }

    public function testItPostsTheTemplateAsJson()
    {
        $this->assertMatchesContract('POST', '/template-messages', 200, self::SENT);
        $client = $this->client(self::answer(self::SENT));

        $sent = $client->sendTemplate(
            from: '5581999990000',
            to: '5511999999999',
            template: 'pedido_saiu',
            language: 'pt_BR',
            body: ['Ana', '123'],
            header: 'Pedido',
        );

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://localhost/api/template-messages', (string) $request->getUri());
        $this->assertSame('Bearer bot-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringStartsWith('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
        $this->assertSame([
            'from' => '5581999990000',
            'to' => '5511999999999',
            'template' => 'pedido_saiu',
            'language' => 'pt_BR',
            'variables' => ['body' => ['Ana', '123'], 'header' => 'Pedido'],
        ], json_decode((string) $request->getBody(), true));

        $this->assertInstanceOf(SentTemplate::class, $sent);
        $this->assertSame('wamid.HBgMNTUxMTk5OTk5OTk5ORUCABEYEjA', $sent->wamid);
        $this->assertSame('5581999990000@c.us', $sent->widFrom);
        $this->assertSame('5511999999999@c.us', $sent->widTo);
        $this->assertSame('Olá, Ana! Seu pedido 123 saiu para entrega.', $sent->previewText);
        $this->assertSame(json_decode(self::SENT, true), $sent->raw());
    }

    public function testWithoutLanguageNorVariablesTheyStayOut()
    {
        $client = $this->client(self::answer(self::SENT));

        $client->sendTemplate('5581999990000', '5511999999999', 'boas_vindas');

        $this->assertSame(
            ['from' => '5581999990000', 'to' => '5511999999999', 'template' => 'boas_vindas'],
            json_decode((string) $this->history[0]['request']->getBody(), true),
        );
    }

    public function testOnlyTheVariablesThatCameAreSent()
    {
        $client = $this->client(self::answer(self::SENT), self::answer(self::SENT));

        $client->sendTemplate('5581999990000', '5511999999999', 'pedido_saiu', body: ['Ana', '123']);
        $client->sendTemplate('5581999990000', '5511999999999', 'pedido_saiu', header: 'Pedido');

        $this->assertSame(['body' => ['Ana', '123']], json_decode((string) $this->history[0]['request']->getBody(), true)['variables']);
        $this->assertSame(['header' => 'Pedido'], json_decode((string) $this->history[1]['request']->getBody(), true)['variables']);
    }

    public function testTheBodyVariablesGoAsAList()
    {
        $client = $this->client(self::answer(self::SENT));

        $client->sendTemplate('5581999990000', '5511999999999', 'pedido_saiu', body: ['nome' => 'Ana', 'pedido' => '123']);

        $this->assertSame(['Ana', '123'], json_decode((string) $this->history[0]['request']->getBody(), true)['variables']['body']);
    }

    public function testAQueuedTemplateHasNoWamid()
    {
        $body = '{"wamid":null,"wid_from":"5581999990000@c.us","wid_to":"5511999999999@c.us","preview_text":null}';
        $this->assertMatchesContract('POST', '/template-messages', 200, $body);

        $sent = $this->client(self::answer($body))->sendTemplate('5581999990000', '5511999999999', 'boas_vindas');

        $this->assertNull($sent->wamid);
        $this->assertNull($sent->previewText);
        $this->assertSame('5511999999999@c.us', $sent->widTo);
    }

    public function testAnAnswerWithoutFieldsIsAnEmptyTemplate()
    {
        $sent = SentTemplate::fromArray([]);

        $this->assertNull($sent->wamid);
        $this->assertNull($sent->widFrom);
        $this->assertNull($sent->widTo);
        $this->assertNull($sent->previewText);
    }

    public function testAJsonAnswerThatIsNotAnObjectIsUnexpected()
    {
        try {
            $this->client(self::answer('[1]'))->sendTemplate('5581999990000', '5511999999999', 'boas_vindas');
            $this->fail('Expected unexpectedResponse.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertNotInstanceOf(ZapmizerApiException::class, $exception);
            $this->assertStringContainsString('response body is not a JSON object', $exception->getMessage());
        }
    }

    public function testWithoutTokenItFailsBeforeAnyRequest()
    {
        $transport = new RecordingTransport();

        try {
            (new Zapmizer(null, $transport, 'http://localhost/api'))->sendTemplate('5581999990000', '5511999999999', 'boas_vindas');
            $this->fail('Expected ZapmizerUnauthorizedException.');
        } catch (ZapmizerUnauthorizedException $exception) {
            $this->assertSame(401, $exception->status());
        }

        $this->assertSame([], $transport->calls);
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testRefusalFromTheContract(int $status, string $body, string $class, ?string $error, string $reason)
    {
        $this->assertMatchesContract('POST', '/template-messages', $status, $body);

        $exception = $this->sendAndCatch(self::answer($body, $status));

        $this->assertSame($class, get_class($exception));
        $this->assertSame($status, $exception->status());
        $this->assertSame($error, $exception->error());
        $this->assertSame($reason, $exception->reason());
    }

    public static function refusalsFromTheContract(): array
    {
        return [
            '401' => [401, '{"message":"Unauthenticated."}', ZapmizerUnauthorizedException::class, null, 'Unauthenticated.'],
            '403' => [403, '{"message":"This connection does not grant this number."}', ZapmizerApiException::class, null, 'This connection does not grant this number.'],
            '404' => [404, '{"message":"Bot instance not found."}', ZapmizerApiException::class, null, 'Bot instance not found.'],
            '409 bot_offline' => [409, '{"error":"bot_offline","message":"Conta do Whatsapp 5581999990000 offline."}', ZapmizerApiException::class, ErrorCode::BOT_OFFLINE, 'Conta do Whatsapp 5581999990000 offline.'],
            '422 refused, only message' => [422, '{"message":"Nenhum template com esse nome neste número."}', ZapmizerApiException::class, null, 'Nenhum template com esse nome neste número.'],
            '422 QR number' => [422, '{"message":"Este número não envia template aprovado."}', ZapmizerApiException::class, null, 'Este número não envia template aprovado.'],
            '422 validation' => [422, '{"message":"The template field is required.","errors":{"template":["The template field is required."]}}', ZapmizerApiException::class, null, 'The template field is required. The template field is required.'],
        ];
    }

    public function testTheValidationKeepsTheFieldErrors()
    {
        $exception = $this->sendAndCatch(self::answer('{"message":"Invalid.","errors":{"variables.body":["The variables.body field must be a list."]}}', 422));

        $this->assertSame(['variables.body' => ['The variables.body field must be a list.']], $exception->errors());
    }

    public function testRateLimitOutsideTheContract()
    {
        $exception = $this->sendAndCatch(self::answer('{"message":"Too Many Attempts."}', 429, ['Retry-After' => '7']));

        $this->assertInstanceOf(ZapmizerRateLimitedException::class, $exception);
        $this->assertSame(7, $exception->retryAfter());
    }

    public function testAProxyErrorIsUnavailable()
    {
        $this->expectException(ZapmizerUnavailableException::class);

        $this->client(new Response(502, [], 'Bad gateway'))->sendTemplate('5581999990000', '5511999999999', 'boas_vindas');
    }

    protected function sendAndCatch(Response $response): ZapmizerApiException
    {
        try {
            $this->client($response)->sendTemplate('5581999990000', '5511999999999', 'boas_vindas');
        } catch (ZapmizerApiException $exception) {
            return $exception;
        }

        $this->fail('Expected ZapmizerApiException.');
    }
}
