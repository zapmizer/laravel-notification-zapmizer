<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Support\Payload;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PayloadTest extends TestCase
{
    /** @dataProvider positiveIds */
    #[DataProvider('positiveIds')]
    public function testPositiveId(mixed $value, ?int $expected)
    {
        $this->assertSame($expected, Payload::positiveId($value));
    }

    public static function positiveIds(): array
    {
        return [
            'int' => [12, 12],
            'one' => [1, 1],
            'largest int' => [PHP_INT_MAX, PHP_INT_MAX],
            'digits' => ['12', 12],
            'leading zero' => ['012', 12],
            '18 digits' => ['999999999999999999', 999999999999999999],
            'absent' => [null, null],
            'zero' => [0, null],
            'negative' => [-1, null],
            'zero string' => ['0', null],
            'zeros string' => ['000', null],
            'negative string' => ['-1', null],
            'decimal string' => ['12.7', null],
            'text' => ['abc', null],
            'empty' => ['', null],
            'padded' => [' 12', null],
            'trailing newline' => ["12\n", null],
            '19 digits' => ['1234567890123456789', null],
            'float' => [12.0, null],
            'bool' => [true, null],
            'array' => [[12], null],
        ];
    }

    /** @dataProvider optionalCounts */
    #[DataProvider('optionalCounts')]
    public function testOptionalCount(mixed $value, ?int $expected)
    {
        $this->assertSame($expected, Payload::optionalCount($value));
    }

    public static function optionalCounts(): array
    {
        return [
            'int' => [3, 3],
            'zero' => [0, 0],
            'digits' => ['3', 3],
            'zero string' => ['0', 0],
            'leading zero' => ['03', 3],
            'absent' => [null, null],
            'negative' => [-1, null],
            'negative string' => ['-1', null],
            'text' => ['x', null],
            'decimal string' => ['1.5', null],
            'float' => [1.5, null],
            'empty' => ['', null],
            'bool' => [true, null],
            '19 digits' => ['1234567890123456789', null],
        ];
    }

    /** @dataProvider readableDates */
    #[DataProvider('readableDates')]
    public function testReadableDateKeepsTheZoneOfTheString(string $value, string $expected)
    {
        Log::spy();

        $date = Payload::date($value, 'expires_at');

        $this->assertSame($expected, $date->format('Y-m-d H:i:s.uP'));
        Log::shouldNotHaveReceived('warning');
    }

    public static function readableDates(): array
    {
        return [
            'Z' => ['2026-09-07T01:00:00Z', '2026-09-07 01:00:00.000000+00:00'],
            'offset zero' => ['2026-09-07T01:00:00+00:00', '2026-09-07 01:00:00.000000+00:00'],
            'microseconds' => ['2026-09-07T01:00:00.123456Z', '2026-09-07 01:00:00.123456+00:00'],
            'milliseconds' => ['2026-09-07T01:00:00.5Z', '2026-09-07 01:00:00.500000+00:00'],
            'negative offset' => ['2026-09-06T23:30:00-03:00', '2026-09-06 23:30:00.000000-03:00'],
            'P30 long fraction' => ['2026-09-07T23:59:59.99999999999999999999Z', '2026-09-07 23:59:59.999999+00:00'],
            'leap day' => ['2028-02-29T00:00:00Z', '2028-02-29 00:00:00.000000+00:00'],
        ];
    }

    /** @dataProvider appTimezones */
    #[DataProvider('appTimezones')]
    public function testP29TheAppTimezoneDoesNotMoveTheDate(string $timezone)
    {
        $previous = date_default_timezone_get();
        config()->set('app.timezone', $timezone);
        date_default_timezone_set($timezone);

        try {
            $utc = Payload::date('2026-09-07T01:00:00Z', 'expires_at');
            $saoPaulo = Payload::date('2026-09-06T23:30:00-03:00', 'expires_at');
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame('2026-09-07 01:00:00+00:00', $utc->format('Y-m-d H:i:sP'));
        $this->assertSame('2026-09-06 23:30:00-03:00', $saoPaulo->format('Y-m-d H:i:sP'));
        $this->assertSame('2026-09-07T02:30:00+00:00', $saoPaulo->utc()->toIso8601String());
    }

    public static function appTimezones(): array
    {
        return ['UTC' => ['UTC'], 'America/Sao_Paulo' => ['America/Sao_Paulo']];
    }

    /** @dataProvider unreadableDates */
    #[DataProvider('unreadableDates')]
    public function testUnreadableDateIsNullAndLogged(mixed $value)
    {
        Log::spy();

        $this->assertNull(Payload::date($value, 'trial_ends_at', '42'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: unreadable date.'
            && $context === ['field' => 'trial_ends_at', 'value' => $value, 'external_id' => '42']);
    }

    public static function unreadableDates(): array
    {
        return [
            'relative' => ['tomorrow'],
            'in portuguese' => ['ontem'],
            'without zone' => ['2026-09-07 01:00:00'],
            'T without zone' => ['2026-09-07T01:00:00'],
            'number' => [123],
            'float' => [1.5],
            'bool' => [true],
            'array' => [['2026-09-07T01:00:00Z']],
            'impossible day' => ['2026-02-30T00:00:00Z'],
            'impossible month' => ['2026-13-01T00:00:00Z'],
            'hour 24' => ['2026-09-07T24:00:00Z'],
            'hour 25' => ['2026-09-07T25:00:00Z'],
            'trailing newline' => ["2026-09-07T01:00:00Z\n"],
            'zone name' => ['2026-09-07T01:00:00 UTC'],
            'date only' => ['2026-09-07'],
        ];
    }

    public function testTheLogCarriesANullExternalIdWhenTheCallHasNone()
    {
        Log::spy();

        Payload::date('tomorrow', 'expires_at');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['field' => 'expires_at', 'value' => 'tomorrow', 'external_id' => null]);
    }

    /** @dataProvider absentDates */
    #[DataProvider('absentDates')]
    public function testAbsentDateIsNullWithoutALog(mixed $value)
    {
        Log::spy();

        $this->assertNull(Payload::date($value, 'expires_at'));

        Log::shouldNotHaveReceived('warning');
    }

    public static function absentDates(): array
    {
        return ['null' => [null], 'empty' => ['']];
    }
}
