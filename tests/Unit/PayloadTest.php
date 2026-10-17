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

    /** @dataProvider unsafeUrls */
    #[DataProvider('unsafeUrls')]
    public function testE3AnUnsafeUrlIsNull(mixed $value)
    {
        Log::spy();

        $this->assertNull(Payload::url($value));

        Log::shouldNotHaveReceived('warning');
    }

    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'without scheme' => ['//h/x'],
            'user and password' => ['https://u:p@h'],
            'empty user' => ['https://@h.com'],
            'backslash' => ['https://h\evil'],
            'ftp' => ['ftp://h'],
            'empty' => [''],
            'absent' => [null],
            'number' => [123],
            'array' => [['https://h.com']],
            'newline in the host' => ["https://h.com\n.evil/x"],
            'tab in the host' => ["https://h\tcom/x"],
            'null byte before an at' => ["https://h.com\x00@evil.com"],
            'delete character' => ["https://h.com/\x7F"],
            'space in the host' => ['https://h com/x'],
            'unicode host' => ['https://ação.com'],
            'encoded slash in the host' => ['https://h.com%2f.evil'],
            'leading space' => [' https://h'],
            'trailing space' => ['https://h.com/ '],
            'one slash' => ['https:/x'],
            'three slashes' => ['https:///x'],
            'no slashes' => ['http:h.com'],
            'port 0' => ['https://h.com:0/x'],
            'port 99999' => ['https://h.com:99999/x'],
            'port 65536' => ['https://h.com:65536/x'],
            'port with letters' => ['https://h.com:44a/x'],
            'semicolon before an at' => ['https://h.com;@evil.com/'],
            'port before an at' => ['https://h.com:443@evil.com/'],
            'port before an at and a port' => ['https://h.com:443@evil.com:443/'],
            'two ports' => ['https://h.com:80:90/x'],
            'port with a letter in the middle' => ['https://h.com:4a4/x'],
            'name between brackets' => ['https://[evil.com]/'],
            'ipv6 with a zone id' => ['https://[fe80::1%25eth0]/'],
            'H1 punycode that does not decode' => ['https://xn--ao-bia.com/x'],
            'H1 punycode of the old test' => ['https://xn--ao-ana.com/x'],
            'H1 empty punycode' => ['https://xn--.com/x'],
            'H1 punycode of one letter' => ['https://xn--a.com/x'],
            'H1 punycode with an invalid digit' => ['https://xn--zz.com/x'],
            'H1 invalid punycode in upper case' => ['https://XN--AO-BIA.com/x'],
            'H1 invalid punycode in a subdomain' => ['https://app.xn--ao-bia.com/x'],
        ];
    }

    /**
     * H1: without `ext-intl` the polyfill provides `idn_to_utf8`. Both must
     * agree on every punycode label of the providers, so the validation is
     * the same on an app without the extension.
     */
    public function testH1ThePolyfillAgreesWithTheExtension()
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('needs ext-intl to compare against');
        }

        $labels = ['xn--bcher-kva', 'xn--55qx5d', 'xn--fiqs8s', 'xn--ls8h', 'xn--ao-bia', 'xn--ao-ana', 'xn--', 'xn--a', 'xn--zz', 'xn--abc-', 'xn---abc', 'xn--4ca0bs'];
        $flags = IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_UNICODE;

        foreach ($labels as $label) {
            $native = idn_to_utf8($label, $flags, INTL_IDNA_VARIANT_UTS46, $nativeInfo);
            $polyfill = \Symfony\Polyfill\Intl\Idn\Idn::idn_to_utf8($label, $flags, INTL_IDNA_VARIANT_UTS46, $polyfillInfo);

            $this->assertSame(
                $native !== false && ($nativeInfo['errors'] ?? 1) === 0,
                $polyfill !== false && ($polyfillInfo['errors'] ?? 1) === 0,
                $label,
            );
        }
    }

    public function testH1AnUnderscoreHostIsNotRunThroughIdna()
    {
        // Only `xn--` labels are decoded: the rest of the host keeps the
        // package's own rules (the underscore of a docker host, a hyphen at
        // the edge of a label), which IDNA would refuse.
        $this->assertSame('http://zapbot_app/api/x', Payload::url('http://zapbot_app/api/x', underscoreInHost: true));
        $this->assertSame('https://-h.com/x', Payload::url('https://-h.com/x'));
        $this->assertNull(Payload::url('http://zapbot_app.xn--ao-bia.com/x', underscoreInHost: true));
    }

    public function testX18eAnUnderscoreInTheHostIsOnlyAcceptedWhenAsked()
    {
        $this->assertNull(Payload::url('https://app_x.parlichat.com/e'));
        $this->assertSame('http://zapbot_app/api/x', Payload::url('http://zapbot_app/api/x', underscoreInHost: true));
    }

    /** @dataProvider unsafeUrls */
    #[DataProvider('unsafeUrls')]
    public function testAnUnsafeUrlIsNullEvenWithTheUnderscoreAllowed(mixed $value)
    {
        $this->assertNull(Payload::url($value, underscoreInHost: true));
    }

    /** @dataProvider safeUrls */
    #[DataProvider('safeUrls')]
    public function testE4ASafeUrlIsKeptAndItsOriginIsTheOneTheBrowserShows(string $url, string $origin)
    {
        Log::spy();

        $this->assertSame($url, Payload::url($url));
        $this->assertSame($origin, Payload::origin($url));

        Log::shouldNotHaveReceived('warning');
    }

    public static function safeUrls(): array
    {
        return [
            'E4 upper case and default port' => ['HTTPS://H.COM:443/x', 'https://h.com'],
            'E4 ipv6 with a port' => ['https://[::1]:8443/x', 'https://[::1]:8443'],
            'E4 punycode' => ['https://xn--bcher-kva.de/x', 'https://xn--bcher-kva.de'],
            'H1 punycode in upper case' => ['https://XN--BCHER-KVA.de/x', 'https://xn--bcher-kva.de'],
            'H1 punycode top-level domain' => ['https://xn--55qx5d.xn--fiqs8s/x', 'https://xn--55qx5d.xn--fiqs8s'],
            'H1 punycode emoji' => ['https://xn--ls8h.la/x', 'https://xn--ls8h.la'],
            'E4 ipv6' => ['https://[::1]/x', 'https://[::1]'],
            'E4 empty port' => ['https://h.com:/x', 'https://h.com'],
            'other port' => ['https://h.com:8443/x', 'https://h.com:8443'],
            'http default port' => ['http://h.com:80/x', 'http://h.com'],
            'http on 443' => ['http://h.com:443/x', 'http://h.com:443'],
            'port with a leading zero' => ['https://h.com:0443/x', 'https://h.com'],
            'lowest port' => ['https://h.com:1/', 'https://h.com:1'],
            'highest port' => ['https://h.com:65535', 'https://h.com:65535'],
            'query and fragment' => ['https://h.com?x=1#y:2', 'https://h.com'],
            'upper case ipv6' => ['https://[::FFFF:7F00:1]/x', 'https://[::ffff:7f00:1]'],
            'ipv4 not canonical' => ['https://0x7f.1/x', 'https://0x7f.1'],
        ];
    }

    /** @dataProvider sameOrigins */
    #[DataProvider('sameOrigins')]
    public function testE6TheSameOriginWrittenTwoWaysIsTheSame(string $url, string $other)
    {
        $this->assertSame(Payload::origin($url), Payload::origin($other));
    }

    public static function sameOrigins(): array
    {
        return [
            'implicit and explicit 443' => ['https://h.com/embed', 'https://h.com:443/chats'],
            'implicit and explicit 80' => ['http://h.com/embed', 'http://h.com:80/chats'],
            'host in another case' => ['https://app.h.com/embed', 'https://APP.H.com/chats'],
            'scheme in another case' => ['https://h.com/embed', 'HTTPS://h.com/chats'],
        ];
    }

    /** @dataProvider otherOrigins */
    #[DataProvider('otherOrigins')]
    public function testE5AnotherHostSchemeOrPortIsAnotherOrigin(string $url, string $other)
    {
        $this->assertNotSame(Payload::origin($url), Payload::origin($other));
    }

    public static function otherOrigins(): array
    {
        return [
            'host' => ['https://h.com/embed', 'https://evil.com/chats'],
            'subdomain' => ['https://h.com/embed', 'https://app.h.com/chats'],
            'scheme' => ['https://h.com/embed', 'http://h.com/chats'],
            'port' => ['https://h.com/embed', 'https://h.com:8443/chats'],
        ];
    }
}
