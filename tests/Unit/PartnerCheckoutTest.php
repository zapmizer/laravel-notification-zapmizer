<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\PartnerCheckout;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PartnerCheckoutTest extends TestCase
{
    use AssertsContract;

    public function testP20AnUnknownDeadlineIsNull()
    {
        $body = '{"url":"https://checkout.test/c/1","expires_at":null}';
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 200, $body);
        Log::spy();

        $checkout = PartnerCheckout::fromArray(json_decode($body, true), '42');

        $this->assertSame('https://checkout.test/c/1', $checkout->url);
        $this->assertNull($checkout->expiresAt);
        Log::shouldNotHaveReceived('warning');
    }

    public function testTheDeadlineIsADate()
    {
        $body = '{"url":"https://checkout.test/c/1","expires_at":"2026-09-07T01:00:00.000000Z"}';
        $this->assertMatchesContract('POST', '/partner/users/{externalId}/checkout', 200, $body);

        $checkout = PartnerCheckout::fromArray(json_decode($body, true), '42');

        $this->assertSame('2026-09-07T01:00:00+00:00', $checkout->expiresAt->toIso8601String());
    }

    public function testAnUnreadableDeadlineIsNullAndLogged()
    {
        Log::spy();

        $checkout = PartnerCheckout::fromArray(['url' => 'https://checkout.test/c/1', 'expires_at' => 'tomorrow'], '42');

        $this->assertNull($checkout->expiresAt);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: unreadable date.'
            && $context === ['field' => 'expires_at', 'value' => 'tomorrow', 'external_id' => '42']);
    }

    /** @dataProvider urlsThatAreNotAUrl */
    #[DataProvider('urlsThatAreNotAUrl')]
    public function testAnAnswerWithoutUrlIsAnUnexpectedResponse(array $payload)
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('missing checkout url');

        PartnerCheckout::fromArray($payload);
    }

    public static function urlsThatAreNotAUrl(): array
    {
        return [
            'absent' => [['expires_at' => null]],
            'empty' => [['url' => '', 'expires_at' => null]],
            'null' => [['url' => null, 'expires_at' => null]],
            'number' => [['url' => 12, 'expires_at' => null]],
        ];
    }
}
