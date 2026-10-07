<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\EmbedSession;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EmbedSessionTest extends TestCase
{
    use AssertsContract;

    public function testTheConversationAnswerOfTheContract()
    {
        $body = '{"url":"https://app.parlichat.com/embed/start/Zr8kQ2","expires_at":"2026-09-26T14:01:00+00:00"}';
        $this->assertMatchesContract('POST', '/embed/sessions', 201, $body);
        Log::spy();

        $session = EmbedSession::fromArray(json_decode($body, true));

        $this->assertSame('https://app.parlichat.com/embed/start/Zr8kQ2', $session->url);
        $this->assertSame('https://app.parlichat.com', $session->origin);
        $this->assertSame('2026-09-26T14:01:00+00:00', $session->expiresAt->toIso8601String());
        $this->assertNull($session->resumeUrl);
        $this->assertNull($session->resumeUntil);
        Log::shouldNotHaveReceived('warning');
    }

    public function testE2TheInboxAnswerOfTheContract()
    {
        $body = '{"url":"https://app.parlichat.com/embed-inbox/start/Zr8kQ2","expires_at":"2026-09-26T14:01:00+00:00","resume_url":"https://app.parlichat.com/chats?embed_inbox=9b2f6c1e-4d7a-4f0e-9a51-2c8e7d3b6a10","resume_until":"2026-09-26T16:01:00+00:00"}';
        $this->assertMatchesContract('POST', '/embed/sessions', 201, $body);
        Log::spy();

        $session = EmbedSession::fromArray(json_decode($body, true));

        $this->assertSame('https://app.parlichat.com/chats?embed_inbox=9b2f6c1e-4d7a-4f0e-9a51-2c8e7d3b6a10', $session->resumeUrl);
        $this->assertSame('2026-09-26T16:01:00+00:00', $session->resumeUntil->toIso8601String());
        $this->assertSame('2026-09-26T14:01:00+00:00', $session->expiresAt->toIso8601String());
        Log::shouldNotHaveReceived('warning');
    }

    /** @dataProvider unsafeUrls */
    #[DataProvider('unsafeUrls')]
    public function testE3AnUnsafeUrlIsAnUnexpectedResponse(array $payload)
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('invalid embed url');

        EmbedSession::fromArray($payload + ['expires_at' => '2026-09-26T14:01:00+00:00']);
    }

    public static function unsafeUrls(): array
    {
        return [
            'E3 absent' => [[]],
            'E3 empty' => [['url' => '']],
            'E3 number' => [['url' => 12]],
            'E3 javascript' => [['url' => 'javascript:alert(1)']],
            'E3 without scheme' => [['url' => '//h/x']],
            'E3 user and password' => [['url' => 'https://u:p@h']],
            'E3 newline in the host' => [['url' => "https://h.com\n.evil/x"]],
            'E3 unicode host' => [['url' => 'https://ação.com']],
            'E3 port with letters' => [['url' => 'https://h.com:44a/x']],
            'E3 ipv6 with a zone id' => [['url' => 'https://[fe80::1%25eth0]/']],
        ];
    }

    /** @dataProvider origins */
    #[DataProvider('origins')]
    public function testE4TheOriginIsTheOneTheBrowserShows(string $url, string $origin)
    {
        $this->assertSame($origin, EmbedSession::fromArray(['url' => $url])->origin);
    }

    public static function origins(): array
    {
        return [
            'E4 upper case and default port' => ['HTTPS://H.COM:443/x', 'https://h.com'],
            'E4 ipv6 with a port' => ['https://[::1]:8443/x', 'https://[::1]:8443'],
            'E4 punycode' => ['https://xn--ao-ana.com/x', 'https://xn--ao-ana.com'],
            'E4 ipv6' => ['https://[::1]/x', 'https://[::1]'],
            'E4 empty port' => ['https://h.com:/x', 'https://h.com'],
            'other port' => ['https://h.com:8443/x', 'https://h.com:8443'],
        ];
    }

    /** @dataProvider resumeUrlsOfAnotherOrigin */
    #[DataProvider('resumeUrlsOfAnotherOrigin')]
    public function testE5AResumeUrlOfAnotherOriginIsNullAndLoggedWithTheOriginsOnly(string $resumeUrl, ?string $resumeOrigin)
    {
        Log::spy();

        $session = EmbedSession::fromArray(['url' => 'https://app.parlichat.com/embed-inbox/start/x', 'resume_url' => $resumeUrl]);

        $this->assertNull($session->resumeUrl);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: unexpected resume url.'
            && $context === ['resume_origin' => $resumeOrigin, 'url_origin' => 'https://app.parlichat.com']);
    }

    public static function resumeUrlsOfAnotherOrigin(): array
    {
        return [
            'E5 host' => ['https://evil.com/chats?embed_inbox=1', 'https://evil.com'],
            'E5 scheme' => ['http://app.parlichat.com/chats?embed_inbox=1', 'http://app.parlichat.com'],
            'E5 port' => ['https://app.parlichat.com:8443/chats?embed_inbox=1', 'https://app.parlichat.com:8443'],
            'E5 unsafe' => ['javascript:alert(1)//app.parlichat.com', null],
            'E5 unsafe with user' => ['https://app.parlichat.com@evil.com/chats', null],
        ];
    }

    /** @dataProvider resumeUrlsOfTheSameOrigin */
    #[DataProvider('resumeUrlsOfTheSameOrigin')]
    public function testE6AResumeUrlOfTheSameOriginWrittenAnotherWayIsKept(string $url, string $resumeUrl)
    {
        Log::spy();

        $this->assertSame($resumeUrl, EmbedSession::fromArray(['url' => $url, 'resume_url' => $resumeUrl])->resumeUrl);

        Log::shouldNotHaveReceived('warning');
    }

    public static function resumeUrlsOfTheSameOrigin(): array
    {
        return [
            'E6 implicit and explicit 443' => ['https://app.parlichat.com/embed-inbox/start/x', 'https://app.parlichat.com:443/chats?embed_inbox=1'],
            'E6 explicit and implicit 443' => ['https://app.parlichat.com:443/embed-inbox/start/x', 'https://app.parlichat.com/chats?embed_inbox=1'],
            'E6 host in another case' => ['https://app.parlichat.com/embed-inbox/start/x', 'https://APP.Parlichat.com/chats?embed_inbox=1'],
        ];
    }

    /** @dataProvider resumeUrlsThatAreAbsent */
    #[DataProvider('resumeUrlsThatAreAbsent')]
    public function testE7AResumeUrlThatIsNotAStringIsNullWithoutAWarning(array $payload)
    {
        Log::spy();

        $this->assertNull(EmbedSession::fromArray(['url' => 'https://app.parlichat.com/embed/start/x'] + $payload)->resumeUrl);

        Log::shouldNotHaveReceived('warning');
    }

    public static function resumeUrlsThatAreAbsent(): array
    {
        return [
            'E7 number' => [['resume_url' => 12]],
            'E7 empty' => [['resume_url' => '']],
            'E7 absent' => [[]],
            'null' => [['resume_url' => null]],
        ];
    }

    /** @dataProvider unreadableDates */
    #[DataProvider('unreadableDates')]
    public function testE8AnUnreadableDateIsNullAndLogged(string $field)
    {
        Log::spy();

        $session = EmbedSession::fromArray(['url' => 'https://app.parlichat.com/embed/start/x', $field => 'tomorrow']);

        $this->assertNull($field === 'expires_at' ? $session->expiresAt : $session->resumeUntil);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'zapmizer: unreadable date.'
            && $context === ['field' => $field, 'value' => 'tomorrow', 'external_id' => null]);
    }

    public static function unreadableDates(): array
    {
        return ['E8 expires_at' => ['expires_at'], 'resume_until' => ['resume_until']];
    }

    public function testAMissingExpiryIsNullWithoutAWarning()
    {
        Log::spy();

        $this->assertNull(EmbedSession::fromArray(['url' => 'https://app.parlichat.com/embed/start/x'])->expiresAt);

        Log::shouldNotHaveReceived('warning');
    }

    public function testTheConstructorTakesTheFiveFieldsAsTheyAre()
    {
        $session = new EmbedSession('https://h.com/x', 'https://h.com');

        $this->assertSame(['https://h.com/x', 'https://h.com', null, null, null], [$session->url, $session->origin, $session->expiresAt, $session->resumeUrl, $session->resumeUntil]);
    }
}
