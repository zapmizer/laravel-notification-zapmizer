<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\ConnectSession;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConnectSessionTest extends TestCase
{
    use AssertsContract;

    /** @dataProvider readableExpiries */
    #[DataProvider('readableExpiries')]
    public function testP6TheExpiryIsADateAndTheJsonIsNormalized(string $expiresAt, string $microseconds)
    {
        $body = json_encode(['url' => 'http://zap.test/connect/1?s=abc', 'expires_at' => $expiresAt]);
        $this->assertMatchesContract('POST', '/connect/sessions', 201, $body);

        $session = ConnectSession::fromArray(json_decode($body, true));

        $this->assertInstanceOf(CarbonImmutable::class, $session->expiresAt);
        $this->assertSame('2026-09-07 01:00:00.' . $microseconds, $session->expiresAt->format('Y-m-d H:i:s.u'));
        $this->assertSame(['url' => 'http://zap.test/connect/1?s=abc', 'expires_at' => '2026-09-07T01:00:00+00:00'], $session->jsonSerialize());
    }

    public static function readableExpiries(): array
    {
        return [
            'Z' => ['2026-09-07T01:00:00Z', '000000'],
            'microseconds' => ['2026-09-07T01:00:00.123456Z', '123456'],
            'offset' => ['2026-09-07T01:00:00+00:00', '000000'],
        ];
    }

    /** @dataProvider unreadableExpiries */
    #[DataProvider('unreadableExpiries')]
    public function testP6AnUnreadableExpiryIsNullAndLogged(mixed $expiresAt)
    {
        Log::spy();

        $session = ConnectSession::fromArray(['url' => 'http://zap.test/connect/1', 'expires_at' => $expiresAt], '42');

        $this->assertNull($session->expiresAt);
        $this->assertSame(['url' => 'http://zap.test/connect/1', 'expires_at' => null], $session->jsonSerialize());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: unreadable date.'
            && $context === ['field' => 'expires_at', 'value' => $expiresAt, 'external_id' => '42']);
    }

    public static function unreadableExpiries(): array
    {
        return [
            'relative' => ['tomorrow'],
            'without zone' => ['2026-09-07 01:00:00'],
            'number' => [123],
            'impossible day' => ['2026-02-30T00:00:00Z'],
        ];
    }

    public function testAMissingExpiryIsNullWithoutALog()
    {
        Log::spy();

        $this->assertNull(ConnectSession::fromArray(['url' => 'http://zap.test/connect/1'])->expiresAt);

        Log::shouldNotHaveReceived('warning');
    }

    public function testTheDataEnvelopeIsStillRead()
    {
        $session = ConnectSession::fromArray(['data' => ['url' => 'http://zap.test/connect/1', 'expires_at' => '2026-09-07T01:00:00Z']]);

        $this->assertSame('http://zap.test/connect/1', $session->url);
        $this->assertSame('2026-09-07T01:00:00+00:00', $session->expiresAt->toIso8601String());
    }

    public function testAMissingUrlIsAnUnexpectedResponse()
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('missing connect session url');

        ConnectSession::fromArray(['expires_at' => '2026-09-07T01:00:00Z']);
    }
}
