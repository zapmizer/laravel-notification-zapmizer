<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\ReconnectResult;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ReconnectResultTest extends TestCase
{
    use AssertsContract;

    public function testR3TheNeedsReconnectAnswerOfTheContract()
    {
        $body = '{"error":"needs_reconnect","message":"O número precisa ser reconectado pelo cliente.","url":"https://app.zapmizer.com/connect/reconnect/9?signature=abc","expires_at":"2026-10-07T15:00:00Z"}';
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 409, $body);

        $result = ReconnectResult::fromNeedsReconnect(json_decode($body, true));

        $this->assertTrue($result->needsClient());
        $this->assertFalse($result->isOnline());
        $this->assertFalse($result->isStarting());
        $this->assertSame(ReconnectResult::NEEDS_CLIENT, $result->status);
        $this->assertSame('https://app.zapmizer.com/connect/reconnect/9?signature=abc', $result->url);
        $this->assertSame('2026-10-07T15:00:00+00:00', $result->expiresAt->toIso8601String());
    }

    public function testR4ANullExpiryIsNullWithoutAWarning()
    {
        $body = '{"error":"needs_reconnect","message":"O número precisa ser reconectado pelo cliente.","url":"https://app.zapmizer.com/connect/reconnect/9","expires_at":null}';
        $this->assertMatchesContract('POST', '/bot-instances/{id}/reconnect', 409, $body);
        Log::spy();

        $result = ReconnectResult::fromNeedsReconnect(json_decode($body, true));

        $this->assertNull($result->expiresAt);
        Log::shouldNotHaveReceived('warning');
    }

    public function testAnAbsentExpiryIsNullWithoutAWarning()
    {
        Log::spy();

        $this->assertNull(ReconnectResult::fromNeedsReconnect(['url' => 'https://app.zapmizer.com/r/9'])->expiresAt);

        Log::shouldNotHaveReceived('warning');
    }

    public function testAnUnreadableExpiryIsNullAndLogged()
    {
        Log::spy();

        $this->assertNull(ReconnectResult::fromNeedsReconnect(['url' => 'https://app.zapmizer.com/r/9', 'expires_at' => 'tomorrow'])->expiresAt);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: unreadable date.'
            && $context === ['field' => 'expires_at', 'value' => 'tomorrow', 'external_id' => null]);
    }

    /** @dataProvider unsafeUrls */
    #[DataProvider('unsafeUrls')]
    public function testR5AMissingOrUnsafeUrlIsAnUnexpectedResponse(array $payload)
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('invalid reconnect url');

        ReconnectResult::fromNeedsReconnect($payload);
    }

    public static function unsafeUrls(): array
    {
        return [
            'absent' => [['error' => 'needs_reconnect', 'expires_at' => null]],
            'empty' => [['url' => '']],
            'spaces' => [['url' => '  ']],
            'array' => [['url' => ['https://app.zapmizer.com/r/9']]],
            'javascript' => [['url' => 'javascript:alert(1)']],
        ];
    }

    public function testTheConstructorTakesTheStateAsItIs()
    {
        $this->assertSame(['online', 'starting', 'needs_client'], [ReconnectResult::ONLINE, ReconnectResult::STARTING, ReconnectResult::NEEDS_CLIENT]);

        $online = new ReconnectResult(ReconnectResult::ONLINE);
        $starting = new ReconnectResult(ReconnectResult::STARTING);

        $this->assertTrue($online->isOnline());
        $this->assertFalse($online->needsClient());
        $this->assertNull($online->url);
        $this->assertNull($online->expiresAt);
        $this->assertTrue($starting->isStarting());
        $this->assertFalse($starting->isOnline());
    }
}
