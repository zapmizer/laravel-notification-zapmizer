<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Support\PhoneNumber;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PhoneNumberTest extends TestCase
{
    public static function normalizationCases(): array
    {
        return [
            'national with ninth digit' => ['11987654321', '5511987654321'],
            'national without ninth digit' => ['1187654321', '551187654321'],
            'already E.164' => ['5511987654321', '5511987654321'],
            'formatted' => ['+55 (81) 99999-8888', '5581999998888'],
            // Without the cut this becomes `081999998888` — 12 digits, "already
            // E.164" by the length rule — and never matches any wid.
            'carrier zero' => ['081 99999-8888', '5581999998888'],
            'carrier zero without ninth digit' => ['081 9999-8888', '558199998888'],
            'outside Brazil' => ['+351 912 345 678', '351912345678'],
            'empty' => [null, ''],
        ];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalizeCompletesCountryCodeAndDropsCarrierZero(?string $typed, string $expected)
    {
        $this->assertEquals($expected, PhoneNumber::normalize($typed));
    }

    public function testNormalizeUsesTheConfiguredCountryCode()
    {
        config()->set('zapmizer.default_country_code', '1');

        // A US number in national format is not Brazilian.
        $this->assertEquals('12125551234', PhoneNumber::normalize('2125551234'));
        $this->assertEquals('12125551234', PhoneNumber::normalize('(212) 555-1234'));
        // Already international: untouched.
        $this->assertEquals('5511987654321', PhoneNumber::normalize('5511987654321'));
        // An explicit code wins over the config.
        $this->assertEquals('5511987654321', PhoneNumber::normalize('11987654321', '55'));
        $this->assertEquals('1', PhoneNumber::defaultCountryCode());
    }

    public function testNormalizeWithoutACountryCodeLeavesNationalNumbersAlone()
    {
        config()->set('zapmizer.default_country_code', null);

        $this->assertEquals('11987654321', PhoneNumber::normalize('11987654321'));
        $this->assertEquals('11987654321', PhoneNumber::normalize('11987654321', ''));
    }

    public function testVariantsOnlyGrowTheNinthDigitForBrazil()
    {
        config()->set('zapmizer.default_country_code', '1');

        // A US number completed with 1 never gets a Brazilian twin.
        $this->assertEquals(['12125551234'], PhoneNumber::variants('2125551234'));
        // A Brazilian E.164 number still does, whatever the default.
        $this->assertEqualsCanonicalizing(['5581999998888', '558199998888'], PhoneNumber::variants('5581999998888'));
    }

    public function testVariantsSwapBetweenMobileFormsOnly()
    {
        $this->assertEqualsCanonicalizing(['5581999998888', '558199998888'], PhoneNumber::variants('5581999998888'));
        $this->assertEqualsCanonicalizing(['558199998888', '5581999998888'], PhoneNumber::variants('558199998888'));
    }

    public static function nonMobileCases(): array
    {
        return [
            'landline' => ['551133334444'],
            'nine digits whose subscriber is not a mobile' => ['5511912345678'],
            'outside Brazil' => ['351912345678'],
        ];
    }

    #[DataProvider('nonMobileCases')]
    public function testVariantsNeverInventAMobileFromALandline(string $number)
    {
        // The 8-digit subscriber of a landline starts with 2-5. Without the
        // filter, `551133334444` would yield `5511933334444`.
        $this->assertEquals([PhoneNumber::normalize($number)], PhoneNumber::variants($number));
    }

    public function testDigitsAndWidHelpers()
    {
        $this->assertEquals('5581999998888', PhoneNumber::digits('+55 (81) 99999-8888'));
        $this->assertEquals('5581999998888', PhoneNumber::fromWid('5581999998888@c.us'));
        $this->assertEquals('', PhoneNumber::fromWid(null));

        $this->assertTrue(PhoneNumber::isGroupWid('120363000000000000@g.us'));
        $this->assertFalse(PhoneNumber::isGroupWid('5581999998888@c.us'));
        $this->assertTrue(PhoneNumber::isLidWid('84474155032797@lid'));
        $this->assertFalse(PhoneNumber::isLidWid('5581999998888@c.us'));
        $this->assertTrue(PhoneNumber::isBroadcastWid('status@broadcast'));
        $this->assertFalse(PhoneNumber::isBroadcastWid('5581999998888@c.us'));
        // The digits of a LID ARE extracted — the caller decides not to use
        // them, by looking at the suffix.
        $this->assertEquals('84474155032797', PhoneNumber::fromWid('84474155032797@lid'));
    }
}
