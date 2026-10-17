<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Connect\ConnectToken;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConnectTokenTest extends TestCase
{
    use AssertsContract;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'token' => '1|sanctum', 'user_id' => 3, 'team_id' => 7, 'team_name' => 'Acme',
            'phone_number' => '5581911110000', 'bot_instance_id' => 9, 'webhook_id' => 42, 'webhook_secret' => 'whsec_new',
        ], $overrides);
    }

    public function testP8TheAnswerOfTheContractCarriesUserAndTeam()
    {
        $body = json_encode($this->payload());
        $this->assertMatchesContract('POST', '/connect/token', 200, $body);

        $token = ConnectToken::fromArray(json_decode($body, true));

        $this->assertSame('1|sanctum', $token->token);
        $this->assertSame(3, $token->userId);
        $this->assertSame(7, $token->teamId);
        $this->assertSame('Acme', $token->teamName);
        $this->assertSame('5581911110000', $token->phoneNumber);
        $this->assertSame(9, $token->botInstanceId);
        $this->assertSame(42, $token->webhookId);
        $this->assertSame('whsec_new', $token->webhookSecret);
        $this->assertTrue($token->hasNumber());
    }

    public function testTheConstructorTakesTheUserSecond()
    {
        $token = new ConnectToken('1|sanctum', 3, 7, 'Acme');

        $this->assertSame(3, $token->userId);
        $this->assertSame(7, $token->teamId);
        $this->assertSame('Acme', $token->teamName);
        $this->assertNull($token->phoneNumber);
    }

    /** @dataProvider idsAsDigits */
    #[DataProvider('idsAsDigits')]
    public function testP9AnIdInDigitsIsRead(string $field, string $value)
    {
        $token = ConnectToken::fromArray($this->payload([$field => $value]));

        $this->assertSame(12, $field === 'user_id' ? $token->userId : $token->teamId);
    }

    public static function idsAsDigits(): array
    {
        return [
            'user_id "12"' => ['user_id', '12'],
            'user_id "012"' => ['user_id', '012'],
            'team_id "12"' => ['team_id', '12'],
            'team_id "012"' => ['team_id', '012'],
        ];
    }

    /** @dataProvider invalidIds */
    #[DataProvider('invalidIds')]
    public function testP9AnInvalidIdIsAnUnexpectedResponse(string $field, bool $present, mixed $value)
    {
        $payload = $this->payload([$field => $value]);

        if (!$present) {
            unset($payload[$field]);
        }

        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage("Zapmizer returned an unexpected response. `invalid {$field}`");

        ConnectToken::fromArray($payload);
    }

    public static function invalidIds(): array
    {
        $cases = [];

        foreach (['user_id', 'team_id'] as $field) {
            $cases["{$field} absent"] = [$field, false, null];
            $cases["{$field} null"] = [$field, true, null];
            $cases["{$field} 0"] = [$field, true, 0];
            $cases["{$field} -1"] = [$field, true, -1];
            $cases["{$field} \"12.7\""] = [$field, true, '12.7'];
            $cases["{$field} \"abc\""] = [$field, true, 'abc'];
            $cases["{$field} 19 digits"] = [$field, true, '1234567890123456789'];
            $cases["{$field} float"] = [$field, true, 12.0];
        }

        return $cases;
    }

    public function testP10WithoutANumberHasNoNumber()
    {
        $body = json_encode($this->payload(['phone_number' => null, 'bot_instance_id' => null, 'webhook_id' => null, 'webhook_secret' => null]));
        $this->assertMatchesContract('POST', '/connect/token', 200, $body);

        $token = ConnectToken::fromArray(json_decode($body, true));

        $this->assertFalse($token->hasNumber());
        $this->assertSame(7, $token->teamId);
    }

    /** @dataProvider halfNumbers */
    #[DataProvider('halfNumbers')]
    public function testHalfANumberIsNoNumber(array $overrides)
    {
        $this->assertFalse(ConnectToken::fromArray($this->payload($overrides))->hasNumber());
    }

    public static function halfNumbers(): array
    {
        return [
            'phone without instance' => [['bot_instance_id' => null]],
            'instance without phone' => [['phone_number' => null]],
            'empty phone' => [['phone_number' => '']],
        ];
    }

    public function testAMissingTokenIsStillAnUnexpectedResponse()
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('missing token');

        ConnectToken::fromArray($this->payload(['token' => '']));
    }
}
