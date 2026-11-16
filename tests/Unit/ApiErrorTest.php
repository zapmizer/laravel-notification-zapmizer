<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use NotificationChannels\Zapmizer\Support\ApiError;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ApiErrorTest extends TestCase
{
    protected function jsonResponse(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
    }

    public function testValidationFormat()
    {
        $error = ApiError::from($this->jsonResponse(422, [
            'message' => 'The redirect uri field is required. (and 1 more error)',
            'errors' => ['redirect_uri' => ['The redirect uri field is required.', 'second'], 'state' => ['The state field is required.']],
        ]));

        $this->assertSame(422, $error->status);
        $this->assertNull($error->error);
        $this->assertSame('The redirect uri field is required. (and 1 more error)', $error->message);
        $this->assertSame(['redirect_uri' => ['The redirect uri field is required.', 'second'], 'state' => ['The state field is required.']], $error->errors);
        $this->assertSame('The redirect uri field is required. (and 1 more error) The redirect uri field is required. The state field is required.', $error->reason());
    }

    public function testCodeFormatKeepsTheExtraFieldsInThePayload()
    {
        $error = ApiError::from($this->jsonResponse(409, [
            'error' => 'window_closed',
            'message' => 'A janela de 24 h fechou.',
            'window_expires_at' => '2026-10-06T10:00:00Z',
        ]));

        $this->assertSame('window_closed', $error->error);
        $this->assertSame('A janela de 24 h fechou.', $error->message);
        $this->assertSame([], $error->errors);
        $this->assertSame('2026-10-06T10:00:00Z', $error->payload['window_expires_at']);
        $this->assertSame('A janela de 24 h fechou.', $error->reason());
    }

    public function testMetaRefusalFormatReadsTheNestedMessage()
    {
        $error = ApiError::from($this->jsonResponse(422, ['error' => ['message' => '(#131026) Message undeliverable.']]));

        $this->assertNull($error->error);
        $this->assertSame('(#131026) Message undeliverable.', $error->message);
        $this->assertSame('(#131026) Message undeliverable.', $error->reason());
    }

    public function testMessageOnlyFormat()
    {
        $error = ApiError::from($this->jsonResponse(401, ['message' => 'Unauthenticated.']));

        $this->assertSame(401, $error->status);
        $this->assertNull($error->error);
        $this->assertSame('Unauthenticated.', $error->reason());
        $this->assertSame('{"message":"Unauthenticated."}', $error->body);
    }

    public function testM32HtmlBodyIsTheReasonCutAt500Characters()
    {
        $html = '<html><body>' . str_repeat('é', 600) . '</body></html>';
        $error = ApiError::from(new Response(403, ['Content-Type' => 'text/html'], "  {$html}  "));

        $this->assertNull($error->error);
        $this->assertNull($error->message);
        $this->assertSame([], $error->payload);
        $this->assertSame(mb_substr($html, 0, 500), $error->body);
        $this->assertSame(500, mb_strlen($error->reason()));
    }

    public function testM33EmptyBodyHasAnEmptyReason()
    {
        $error = ApiError::from(new Response(404, [], ''));

        $this->assertSame('', $error->body);
        $this->assertSame('', $error->reason());
        $this->assertSame([], $error->payload);
    }

    /** @dataProvider oddErrorValues */
    #[DataProvider('oddErrorValues')]
    public function testM34ErrorThatIsNotANonEmptyStringIsNull(mixed $value, ?string $message)
    {
        $error = ApiError::from($this->jsonResponse(422, ['error' => $value]));

        $this->assertNull($error->error);
        $this->assertSame($message, $error->message);
    }

    public static function oddErrorValues(): array
    {
        return [
            'object without message' => [['code' => 1], null],
            'object with a non-string message' => [['message' => 12], null],
            'number' => [131026, null],
            'empty string' => ['', null],
            'null' => [null, null],
        ];
    }

    public function testM34TopLevelMessageWinsOverTheNestedOne()
    {
        $error = ApiError::from($this->jsonResponse(422, ['message' => 'top', 'error' => ['message' => 'nested']]));

        $this->assertSame('top', $error->message);
    }

    public function testM35FieldErrorsAreNormalized()
    {
        $error = ApiError::from($this->jsonResponse(422, [
            'message' => 'Invalid.',
            'errors' => [
                'to' => 'The to field is required.',
                'from' => ['first', 7, null, 'second'],
                'type' => 12,
                'metadata' => ['nested' => 'kept'],
                'empty' => [],
            ],
        ]));

        $this->assertSame([
            'to' => ['The to field is required.'],
            'from' => ['first', 'second'],
            'metadata' => ['kept'],
        ], $error->errors);
        $this->assertSame('Invalid. The to field is required. first kept', $error->reason());
    }

    /** @dataProvider notAFieldMap */
    #[DataProvider('notAFieldMap')]
    public function testM35ErrorsThatAreNotAFieldMapAreDropped(mixed $errors)
    {
        $this->assertSame([], ApiError::from($this->jsonResponse(422, ['message' => 'x', 'errors' => $errors]))->errors);
    }

    public static function notAFieldMap(): array
    {
        return [
            'list' => [['first', 'second']],
            'empty' => [[]],
            'string' => ['broken'],
            'number' => [1],
        ];
    }

    public function testPayloadIsEmptyForAListOrAScalar()
    {
        $this->assertSame([], ApiError::from($this->jsonResponse(422, ['a', 'b']))->payload);
        $this->assertSame([], ApiError::from($this->jsonResponse(422, 'text'))->payload);
        $this->assertSame([], ApiError::from(new Response(422, [], '{}'))->payload);
    }

    public function testJsonWithoutMessageOrErrorsFallsBackToTheBody()
    {
        $error = ApiError::from($this->jsonResponse(409, ['error' => 'bot_offline']));

        $this->assertSame('bot_offline', $error->error);
        $this->assertSame('{"error":"bot_offline"}', $error->reason());
    }

    public function testFieldErrorsWithoutAMessageAreTheReason()
    {
        $this->assertSame('first other', ApiError::from($this->jsonResponse(422, ['errors' => ['a' => ['first', 'second'], 'b' => ['other']]]))->reason());
    }

    /** @dataProvider retryAfterValues */
    #[DataProvider('retryAfterValues')]
    public function testM4RetryAfterIsOnlyDigits(?string $header, ?int $expected)
    {
        $headers = $header === null ? [] : ['Retry-After' => $header];

        $this->assertSame($expected, ApiError::from(new Response(429, $headers, '{"message":"Too Many Attempts."}'))->retryAfter);
    }

    public static function retryAfterValues(): array
    {
        return [
            'seconds' => ['12', 12],
            'zero' => ['0', 0],
            'padded' => [' 30 ', 30],
            'absent' => [null, null],
            'empty' => ['', null],
            'http date' => ['Wed, 21 Oct 2026 07:28:00 GMT', null],
            'negative' => ['-1', null],
            'text' => ['abc', null],
            'decimal' => ['1.5', null],
        ];
    }

    public function testRetryAfterSentTwiceIsNull()
    {
        $this->assertNull(ApiError::from(new Response(429, ['Retry-After' => ['5', '10']], '{}'))->retryAfter);
    }

    public function testABodyThatIsNotUtf8DoesNotBreakTheReading()
    {
        $raw = "\xff\xfe" . str_repeat("\x00\x01", 400);
        $error = ApiError::from(new Response(502, ['Content-Type' => 'application/octet-stream'], $raw));

        $this->assertSame(mb_substr(trim($raw), 0, 500), $error->body);
        $this->assertLessThanOrEqual(500, mb_strlen($error->body));
        $this->assertNotSame('', $error->body);
        $this->assertSame($error->body, $error->reason());
        $this->assertNull($error->message);
    }

    public function testM48ANonSeekableBodyIsReadOnce()
    {
        $body = new NoSeekStream(Utils::streamFor('{"message":"The redirect uri field is required.","errors":{"redirect_uri":["required"]}}'));
        $error = ApiError::from(new Response(422, ['Content-Type' => 'application/json'], $body));

        $this->assertSame('The redirect uri field is required. required', $error->reason());
        $this->assertSame(['redirect_uri' => ['required']], $error->errors);
    }

    public function testUnauthenticatedHasNoResponse()
    {
        $error = ApiError::unauthenticated('You must provide your zapmizer bot token to make any API requests.');

        $this->assertSame(401, $error->status);
        $this->assertNull($error->error);
        $this->assertSame('You must provide your zapmizer bot token to make any API requests.', $error->message);
        $this->assertSame([], $error->errors);
        $this->assertSame([], $error->payload);
        $this->assertSame('', $error->body);
        $this->assertNull($error->retryAfter);
        $this->assertSame('You must provide your zapmizer bot token to make any API requests.', $error->reason());
    }
}
