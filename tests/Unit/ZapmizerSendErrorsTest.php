<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Zapmizer;
use PHPUnit\Framework\Attributes\DataProvider;

class ZapmizerSendErrorsTest extends TestCase
{
    use AssertsContract;

    protected function sendAndCatch(Response $response): ZapmizerApiException
    {
        $client = new Zapmizer('bot-token', new RecordingTransport($response), 'http://localhost/api');

        try {
            $client->sendMessage(['type' => 'chat', 'from' => '5581999990000', 'to' => '5511999999999', 'metadata' => ['text' => 'hi']]);
        } catch (ZapmizerApiException $exception) {
            return $exception;
        }

        $this->fail('Expected ZapmizerApiException.');
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testRefusalFromTheContract(int $status, string $body, ?string $error, string $reason, string $message)
    {
        $this->assertMatchesContract('POST', '/messages', $status, $body);

        $exception = $this->sendAndCatch(new Response($status, ['Content-Type' => 'application/json'], $body));

        $this->assertSame(ZapmizerApiException::class, get_class($exception));
        $this->assertSame($status, $exception->status());
        $this->assertSame($error, $exception->error());
        $this->assertSame($reason, $exception->reason());
        $this->assertSame($message, $exception->getMessage());
    }

    public static function refusalsFromTheContract(): array
    {
        return [
            'M16 409 window_closed' => [
                409,
                '{"error":"window_closed","message":"A janela de 24 h fechou.","window_expires_at":"2026-10-06T10:00:00Z"}',
                ErrorCode::WINDOW_CLOSED,
                'A janela de 24 h fechou.',
                'Zapmizer refused the request (HTTP 409, window_closed): A janela de 24 h fechou.',
            ],
            '409 bot_offline without message' => [
                409,
                '{"error":"bot_offline"}',
                ErrorCode::BOT_OFFLINE,
                '{"error":"bot_offline"}',
                'Zapmizer refused the request (HTTP 409, bot_offline): {"error":"bot_offline"}.',
            ],
            'M17 422 refused by Meta' => [
                422,
                '{"error":{"message":"(#131026) Message undeliverable."}}',
                null,
                '(#131026) Message undeliverable.',
                'Zapmizer refused the request (HTTP 422): (#131026) Message undeliverable.',
            ],
            'M18 422 recipient_not_found' => [
                422,
                '{"error":"recipient_not_found","message":"O número de destino não tem WhatsApp."}',
                ErrorCode::RECIPIENT_NOT_FOUND,
                'O número de destino não tem WhatsApp.',
                'Zapmizer refused the request (HTTP 422, recipient_not_found): O número de destino não tem WhatsApp.',
            ],
            '422 attachment_unreachable' => [
                422,
                '{"error":"attachment_unreachable","message":"O anexo não pôde ser baixado."}',
                ErrorCode::ATTACHMENT_UNREACHABLE,
                'O anexo não pôde ser baixado.',
                'Zapmizer refused the request (HTTP 422, attachment_unreachable): O anexo não pôde ser baixado.',
            ],
            'M20 403' => [
                403,
                '{"message":"This connection does not grant this number."}',
                null,
                'This connection does not grant this number.',
                'Zapmizer refused the request (HTTP 403): This connection does not grant this number.',
            ],
            'M21 404' => [
                404,
                '{"message":"Not found."}',
                null,
                'Not found.',
                'Zapmizer refused the request (HTTP 404): Not found.',
            ],
        ];
    }

    public function testM19ValidationKeepsTheFieldErrors()
    {
        $body = '{"message":"The to field is required.","errors":{"to":["The to field is required."]}}';
        $this->assertMatchesContract('POST', '/messages', 422, $body);

        $exception = $this->sendAndCatch(new Response(422, ['Content-Type' => 'application/json'], $body));

        $this->assertNull($exception->error());
        $this->assertSame(['to' => ['The to field is required.']], $exception->errors());
    }

    public function testM41TheExtraFieldsOfTheContractAreInThePayload()
    {
        $body = '{"error":"window_closed","message":"A janela de 24 h fechou.","window_expires_at":"2026-10-06T10:00:00Z"}';
        $this->assertMatchesContract('POST', '/messages', 409, $body);

        $exception = $this->sendAndCatch(new Response(409, ['Content-Type' => 'application/json'], $body));

        $this->assertSame('2026-10-06T10:00:00Z', $exception->payload()['window_expires_at']);
    }

    /** @dataProvider refusalsOutsideTheContract */
    #[DataProvider('refusalsOutsideTheContract')]
    public function testRefusalOutsideTheContract(Response $response, string $class, ?string $error, string $reason)
    {
        $exception = $this->sendAndCatch($response);

        $this->assertSame($class, get_class($exception));
        $this->assertSame($response->getStatusCode(), $exception->status());
        $this->assertSame($error, $exception->error());
        $this->assertSame($reason, $exception->reason());
    }

    public static function refusalsOutsideTheContract(): array
    {
        $html = '<html><body>' . str_repeat('x', 800) . '</body></html>';

        return [
            '429 with Retry-After' => [new Response(429, ['Retry-After' => '10'], '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class, null, 'Too Many Attempts.'],
            'M32 html from a proxy' => [new Response(413, ['Content-Type' => 'text/html'], $html), ZapmizerApiException::class, null, substr($html, 0, 500)],
            'M33 empty body' => [new Response(400, [], ''), ZapmizerApiException::class, null, ''],
            'M34 error as a number' => [new Response(422, ['Content-Type' => 'application/json'], '{"error":131026,"message":"Meta."}'), ZapmizerApiException::class, null, 'Meta.'],
            'M34 error object without message' => [new Response(422, ['Content-Type' => 'application/json'], '{"error":{"code":131026}}'), ZapmizerApiException::class, null, '{"error":{"code":131026}}'],
            'M35 errors as strings' => [new Response(422, ['Content-Type' => 'application/json'], '{"message":"Invalid.","errors":{"to":"The to field is required."}}'), ZapmizerApiException::class, null, 'Invalid. The to field is required.'],
        ];
    }

    public function testRateLimitCarriesTheRetryAfterOutsideTheContract()
    {
        $exception = $this->sendAndCatch(new Response(429, ['Retry-After' => '10'], '{"message":"Too Many Attempts."}'));

        $this->assertSame(10, $exception->retryAfter());
    }

    public function testM33AnEmptyBodyLeavesTheReasonOutOfTheMessageOutsideTheContract()
    {
        $this->assertSame('Zapmizer refused the request (HTTP 400).', $this->sendAndCatch(new Response(400, [], ''))->getMessage());
    }
}
