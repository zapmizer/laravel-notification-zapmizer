<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Messages\MessageStatus;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use ReflectionClass;

class MessageStatusTest extends TestCase
{
    use AssertsContract;

    public function testTheConstantsAreTheScaleOfTheContract()
    {
        $values = array_values((new ReflectionClass(MessageStatus::class))->getConstants());
        sort($values);

        $this->assertSame(self::contractDocument()->components->schemas->StatusDaMensagem->enum, $values);

        foreach ($values as $value) {
            $this->assertMatchesSchema('#/components/schemas/StatusDaMensagem', $value);
        }
    }

    public function testTheValuesFollowTheLlmsScale()
    {
        $this->assertSame(-4, MessageStatus::FAILED_BEFORE_SENDING);
        $this->assertSame(-3, MessageStatus::WAITING);
        $this->assertSame(-2, MessageStatus::QUEUED);
        $this->assertSame(-1, MessageStatus::FAILED);
        $this->assertSame(0, MessageStatus::SENDING);
        $this->assertSame(1, MessageStatus::SENT);
        $this->assertSame(2, MessageStatus::DELIVERED);
        $this->assertSame(3, MessageStatus::READ);
        $this->assertSame(4, MessageStatus::PLAYED);
    }

    public function testFailuresAreMinusFourAndMinusOne()
    {
        foreach ([-4, -1] as $status) {
            $this->assertTrue(MessageStatus::isFailure($status), (string) $status);
        }

        foreach ([-3, -2, 0, 1, 2, 3, 4, 5, -5] as $status) {
            $this->assertFalse(MessageStatus::isFailure($status), (string) $status);
        }
    }

    public function testFinalStatusesAreTheFailuresReadAndPlayed()
    {
        foreach ([-4, -1, 3, 4] as $status) {
            $this->assertTrue(MessageStatus::isFinal($status), (string) $status);
        }

        foreach ([-3, -2, 0, 1, 2, 5, -5] as $status) {
            $this->assertFalse(MessageStatus::isFinal($status), (string) $status);
        }
    }

    public function testReachedComparesTheProgressByTheNumber()
    {
        $this->assertTrue(MessageStatus::reached(MessageStatus::READ, MessageStatus::DELIVERED));
        $this->assertTrue(MessageStatus::reached(MessageStatus::DELIVERED, MessageStatus::DELIVERED));
        $this->assertFalse(MessageStatus::reached(MessageStatus::SENT, MessageStatus::DELIVERED));
        $this->assertFalse(MessageStatus::reached(MessageStatus::FAILED, MessageStatus::SENDING));
        $this->assertFalse(MessageStatus::reached(MessageStatus::QUEUED, MessageStatus::SENDING));
        $this->assertTrue(MessageStatus::reached(5, MessageStatus::PLAYED));
    }

    public function testAFailureReachesNothing()
    {
        $this->assertFalse(MessageStatus::reached(MessageStatus::FAILED, MessageStatus::FAILED_BEFORE_SENDING));
        $this->assertFalse(MessageStatus::reached(MessageStatus::FAILED_BEFORE_SENDING, MessageStatus::FAILED_BEFORE_SENDING));
    }
}
