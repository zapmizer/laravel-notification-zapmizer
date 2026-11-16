<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;
use NotificationChannels\Zapmizer\Support\ApiError;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;

class ZapmizerApiExceptionTest extends TestCase
{
    protected function error(int $status, string $body, array $headers = []): ApiError
    {
        return ApiError::from(new Response($status, ['Content-Type' => 'application/json'] + $headers, $body));
    }

    /** @dataProvider defaultMessages */
    #[DataProvider('defaultMessages')]
    public function testDefaultMessage(int $status, string $body, string $expected)
    {
        $this->assertSame($expected, (new ZapmizerApiException($this->error($status, $body)))->getMessage());
    }

    public static function defaultMessages(): array
    {
        return [
            'code and reason' => [409, '{"error":"window_closed","message":"A janela de 24 h fechou."}', 'Zapmizer refused the request (HTTP 409, window_closed): A janela de 24 h fechou.'],
            'reason without code' => [404, '{"message":"Not found"}', 'Zapmizer refused the request (HTTP 404): Not found.'],
            'trailing dots trimmed' => [403, '{"message":"Wait..."}', 'Zapmizer refused the request (HTTP 403): Wait.'],
            'M33 empty body' => [404, '', 'Zapmizer refused the request (HTTP 404).'],
        ];
    }

    public function testAccessorsReadTheApiError()
    {
        $error = $this->error(409, '{"error":"window_closed","message":"Fechou.","window_expires_at":"2026-10-06T10:00:00Z","errors":{"to":["bad"]}}');
        $previous = new RuntimeException('cause');
        $exception = new ZapmizerApiException($error, 'custom', $previous);

        $this->assertSame('custom', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame(409, $exception->status());
        $this->assertSame('window_closed', $exception->error());
        $this->assertSame('Fechou. bad', $exception->reason());
        $this->assertSame(['to' => ['bad']], $exception->errors());
        $this->assertSame('2026-10-06T10:00:00Z', $exception->payload()['window_expires_at']);
        $this->assertSame($error, $exception->apiError());
    }

    public function testRateLimitedMessageCarriesTheRetryAfter()
    {
        $limited = new ZapmizerRateLimitedException($this->error(429, '{"message":"Too Many Attempts."}', ['Retry-After' => '12']));
        $withoutHeader = new ZapmizerRateLimitedException($this->error(429, '{"message":"Too Many Attempts."}'));

        $this->assertSame('Zapmizer rate-limited the request. Retry in 12 s.', $limited->getMessage());
        $this->assertSame(12, $limited->retryAfter());
        $this->assertSame(429, $limited->status());
        $this->assertSame('Zapmizer rate-limited the request.', $withoutHeader->getMessage());
        $this->assertNull($withoutHeader->retryAfter());
        $this->assertSame('custom', (new ZapmizerRateLimitedException($this->error(429, '{}'), 'custom'))->getMessage());
    }

    public function testRootOfTheHierarchy()
    {
        $api = new ZapmizerApiException($this->error(422, '{}'));

        $this->assertInstanceOf(ZapmizerConnectException::class, $api);
        $this->assertInstanceOf(ZapmizerException::class, $api);
        $this->assertInstanceOf(ZapmizerApiException::class, new ZapmizerRateLimitedException($this->error(429, '{}')));
        $this->assertInstanceOf(ZapmizerException::class, ZapmizerUnavailableException::dueTo());
        $this->assertInstanceOf(ZapmizerException::class, ZapmizerVerificationException::apiTokenNotProvided());
        $this->assertNotInstanceOf(ZapmizerApiException::class, ZapmizerUnavailableException::dueTo());
        $this->assertTrue((new ReflectionClass(ZapmizerException::class))->isAbstract());
    }
}
