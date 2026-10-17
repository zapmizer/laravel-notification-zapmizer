<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\PartnerSubscription;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PartnerSubscriptionTest extends TestCase
{
    use AssertsContract;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'user_id' => 3, 'team_id' => 7, 'external_id' => '42', 'subscribed' => false,
            'quantity' => null, 'trial_ends_at' => null, 'payment_incomplete' => false,
        ], $overrides);
    }

    /** @dataProvider answersFromTheContract */
    #[DataProvider('answersFromTheContract')]
    public function testP13TheAnswerOfTheContract(array $overrides, bool $hasAccess)
    {
        $body = json_encode($this->payload($overrides));
        $this->assertMatchesContract('GET', '/partner/users/{externalId}', 200, $body);

        $subscription = PartnerSubscription::fromArray(json_decode($body, true), '42');

        $this->assertSame(3, $subscription->userId);
        $this->assertSame(7, $subscription->teamId);
        $this->assertSame('42', $subscription->externalId);
        $this->assertSame($hasAccess, $subscription->hasAccess(new DateTimeImmutable('2026-09-01T00:00:00Z')));
    }

    public static function answersFromTheContract(): array
    {
        return [
            'subscriber' => [['subscribed' => true, 'quantity' => 2], true],
            'future trial' => [['trial_ends_at' => '2026-09-07T01:00:00Z'], true],
            'no access' => [['payment_incomplete' => true], false],
        ];
    }

    public function testEveryFieldIsRead()
    {
        $subscription = PartnerSubscription::fromArray($this->payload([
            'subscribed' => true, 'quantity' => 2, 'trial_ends_at' => '2026-09-07T01:00:00.5Z', 'payment_incomplete' => true,
        ]));

        $this->assertTrue($subscription->subscribed);
        $this->assertSame(2, $subscription->quantity);
        $this->assertSame('2026-09-07 01:00:00.500000+00:00', $subscription->trialEndsAt->format('Y-m-d H:i:s.uP'));
        $this->assertTrue($subscription->paymentIncomplete);
    }

    public function testP14APastTrialGivesNoAccess()
    {
        $subscription = PartnerSubscription::fromArray($this->payload(['trial_ends_at' => '2026-08-01T00:00:00Z']));

        $this->assertFalse($subscription->hasAccess(new DateTimeImmutable('2026-09-01T00:00:00Z')));
    }

    public function testATrialEndingNowGivesNoAccess()
    {
        $subscription = PartnerSubscription::fromArray($this->payload(['trial_ends_at' => '2026-09-01T00:00:00Z']));

        $this->assertFalse($subscription->hasAccess(CarbonImmutable::parse('2026-09-01T00:00:00Z')));
    }

    public function testATrialInAnotherZoneIsComparedByTheInstant()
    {
        $subscription = PartnerSubscription::fromArray($this->payload(['trial_ends_at' => '2026-09-06T23:30:00-03:00']));

        $this->assertTrue($subscription->hasAccess(new DateTimeImmutable('2026-09-07T02:00:00Z')));
        $this->assertFalse($subscription->hasAccess(new DateTimeImmutable('2026-09-07T03:00:00Z')));
    }

    public function testWithoutNowTheClockDecides()
    {
        $this->assertTrue(PartnerSubscription::fromArray($this->payload(['trial_ends_at' => '2999-01-01T00:00:00Z']))->hasAccess());
        $this->assertFalse(PartnerSubscription::fromArray($this->payload(['trial_ends_at' => '2000-01-01T00:00:00Z']))->hasAccess());
    }

    /** @dataProvider quantities */
    #[DataProvider('quantities')]
    public function testP15Quantity(mixed $quantity, ?int $expected)
    {
        $this->assertSame($expected, PartnerSubscription::fromArray($this->payload(['quantity' => $quantity]))->quantity);
    }

    public static function quantities(): array
    {
        return [
            'string "3"' => ['3', 3],
            'string "0"' => ['0', 0],
            'int 3' => [3, 3],
            'negative' => [-1, null],
            'text' => ['x', null],
            'null' => [null, null],
        ];
    }

    public function testP16AnUnreadableTrialIsNullAndLogged()
    {
        Log::spy();

        $subscription = PartnerSubscription::fromArray($this->payload(['trial_ends_at' => 'ontem']), '42');

        $this->assertNull($subscription->trialEndsAt);
        $this->assertFalse($subscription->hasAccess());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: unreadable date.'
            && $context === ['field' => 'trial_ends_at', 'value' => 'ontem', 'external_id' => '42']);
    }

    /** @dataProvider flagsThatAreNotTrue */
    #[DataProvider('flagsThatAreNotTrue')]
    public function testOnlyTrueIsTrue(mixed $flag)
    {
        $subscription = PartnerSubscription::fromArray($this->payload(['subscribed' => $flag, 'payment_incomplete' => $flag]));

        $this->assertFalse($subscription->subscribed);
        $this->assertFalse($subscription->paymentIncomplete);
    }

    public static function flagsThatAreNotTrue(): array
    {
        return ['string' => ['true'], 'one' => [1], 'null' => [null], 'false' => [false]];
    }

    /** @dataProvider invalidAnswers */
    #[DataProvider('invalidAnswers')]
    public function testP28AnAnswerWithoutItsIdsIsAnUnexpectedResponse(array $overrides, string $reason)
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage("Zapmizer returned an unexpected response. `{$reason}`");

        PartnerSubscription::fromArray($this->payload($overrides));
    }

    public static function invalidAnswers(): array
    {
        return [
            'P28 external_id as a number' => [['external_id' => 12], 'invalid external_id'],
            'external_id empty' => [['external_id' => ''], 'invalid external_id'],
            'external_id null' => [['external_id' => null], 'invalid external_id'],
            'user_id 0' => [['user_id' => 0], 'invalid user_id'],
            'user_id "abc"' => [['user_id' => 'abc'], 'invalid user_id'],
            'team_id null' => [['team_id' => null], 'invalid team_id'],
            'team_id "12.7"' => [['team_id' => '12.7'], 'invalid team_id'],
        ];
    }

    public function testIdsInDigitsAreRead()
    {
        $subscription = PartnerSubscription::fromArray($this->payload(['user_id' => '012', 'team_id' => '7']));

        $this->assertSame(12, $subscription->userId);
        $this->assertSame(7, $subscription->teamId);
    }

    public function testTheExternalIdIsKeptAsItCame()
    {
        $this->assertSame('other', PartnerSubscription::fromArray($this->payload(['external_id' => 'other']), '42')->externalId);
    }
}
