<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\ConnectSession;
use NotificationChannels\Zapmizer\Connect\ConnectToken;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PartnerClientTest extends TestCase
{
    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): PartnerClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new PartnerClient('partner-id', 'partner-secret', new HttpClient(['handler' => $stack]), 'http://localhost/api');
    }

    public function testCreateSessionSendsPartnerKeyAndReturnsPopupUrl()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode(['url' => 'http://localhost/connect/1?signature=abc', 'expires_at' => '2026-09-07T01:00:00.000000Z'])),
        ]));

        $session = $client->createSession('http://app.test/zapmizer/connect/callback', 'state-123', 'http://app.test/zapmizer/webhook');

        $this->assertInstanceOf(ConnectSession::class, $session);
        $this->assertEquals('http://localhost/connect/1?signature=abc', $session->url);
        $this->assertEquals('2026-09-07T01:00:00.000000Z', $session->expiresAt);
        $this->assertEquals(['url' => $session->url, 'expires_at' => $session->expiresAt], $session->jsonSerialize());

        $request = $this->history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://localhost/api/connect/sessions', (string) $request->getUri());
        $this->assertEquals('partner-id|partner-secret', $request->getHeaderLine('X-Partner-Key'));
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertEquals(
            ['redirect_uri' => 'http://app.test/zapmizer/connect/callback', 'state' => 'state-123', 'webhook_url' => 'http://app.test/zapmizer/webhook'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testCreateSessionWithoutAWebhookSendsNone()
    {
        $client = $this->makeClient(new MockHandler([new Response(201, [], json_encode(['url' => 'http://localhost/connect/1']))]));

        $client->createSession('http://app.test/cb', 'state');

        $this->assertEquals(['redirect_uri' => 'http://app.test/cb', 'state' => 'state'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    #[DataProvider('expiresIn')]
    public function testCreateSessionNeverAsksForLessThanZapmizerAllows(int $requested, int $sent)
    {
        // Below 900 the signed session dies while the user is still reading
        // the QR code — Zapmizer refuses it, so the floor is applied here.
        $client = $this->makeClient(new MockHandler([new Response(201, [], json_encode(['url' => 'http://localhost/connect/1']))]));

        $client->createSession('http://app.test/cb', 'state', null, $requested);

        $this->assertEquals($sent, json_decode((string) $this->history[0]['request']->getBody(), true)['expires_in']);
        $this->assertGreaterThanOrEqual(PartnerClient::MIN_EXPIRES_IN, $sent);
    }

    public static function expiresIn(): array
    {
        return ['below the floor' => [300, 900], 'at the floor' => [900, 900], 'above' => [3600, 3600]];
    }

    public function testExchangeCodeReturnsTheTeamTokenWithThePairing()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode([
                'token' => '1|sanctum', 'team_id' => 7, 'team_name' => 'Acme',
                'phone_number' => '5511999990000', 'bot_instance_id' => 42, 'webhook_id' => 9, 'webhook_secret' => 'whsec_x',
            ])),
        ]));

        $token = $client->exchangeCode('12.secret');

        $this->assertInstanceOf(ConnectToken::class, $token);
        $this->assertEquals('1|sanctum', $token->token);
        $this->assertEquals(7, $token->teamId);
        $this->assertEquals('Acme', $token->teamName);
        $this->assertEquals('5511999990000', $token->phoneNumber);
        $this->assertEquals(42, $token->botInstanceId);
        $this->assertEquals(9, $token->webhookId);
        $this->assertEquals('whsec_x', $token->webhookSecret);
        $this->assertFalse($token->needsWebhookSecret());
        $this->assertEquals(['code' => '12.secret'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testExchangeCodeTellsAReusedWebhookApart()
    {
        $client = $this->makeClient(new MockHandler([
            // Webhook reused on the team: id comes, secret does not.
            new Response(200, [], json_encode(['token' => '1|sanctum', 'team_id' => 7, 'phone_number' => '5511999990000', 'bot_instance_id' => 42, 'webhook_id' => 9, 'webhook_secret' => null])),
            // No webhook_url was sent: nothing to rotate.
            new Response(200, [], json_encode(['token' => '1|sanctum', 'team_id' => 7, 'phone_number' => '5511999990000', 'bot_instance_id' => 42, 'webhook_id' => null, 'webhook_secret' => null])),
        ]));

        $reused = $client->exchangeCode('c');
        $this->assertEquals(9, $reused->webhookId);
        $this->assertNull($reused->webhookSecret);
        $this->assertTrue($reused->needsWebhookSecret());

        $none = $client->exchangeCode('c');
        $this->assertNull($none->webhookId);
        $this->assertFalse($none->needsWebhookSecret());
    }

    public function testExchangeCodeReturnsNullForUnknownCode()
    {
        $client = $this->makeClient(new MockHandler([new Response(404, [], '{"message":"Record not found."}')]));

        $this->assertNull($client->exchangeCode('bad'));
    }

    #[DataProvider('credentialFailures')]
    public function testRefusedPartnerKeyBecomesTypedExceptionAndIsLogged(int $status)
    {
        Log::spy();
        $client = $this->makeClient(new MockHandler([new Response($status, [], '{"message":"Unauthenticated."}')]));

        try {
            $client->createSession('http://app.test/cb', 'state');
            $this->fail('Expected PartnerCredentialsException.');
        } catch (PartnerCredentialsException $exception) {
            $this->assertEquals(503, $exception->render()->getStatusCode());
            $this->assertEquals(['code' => 'partner_unauthorized'], $exception->render()->getData(true));
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['status'] === $status);
    }

    public static function credentialFailures(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    public function testOtherClientErrorsBecomeUnavailable()
    {
        $client = $this->makeClient(new MockHandler([new Response(422, [], '{"message":"The redirect uri field is required."}')]));

        try {
            $client->createSession('http://app.test/cb', 'state');
            $this->fail('Expected ZapmizerUnavailableException.');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertEquals(['code' => 'zapmizer_unavailable'], $exception->render()->getData(true));
            $this->assertStringNotContainsString('redirect uri', $exception->render()->getContent());
        }
    }

    public function testServerErrorAndNetworkFailureBecomeUnavailable()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(500, [], 'boom'),
            new ConnectException('timed out', new Request('POST', 'connect/sessions')),
        ]));

        foreach ([1, 2] as $attempt) {
            try {
                $client->createSession('http://app.test/cb', 'state');
                $this->fail('Expected ZapmizerUnavailableException.');
            } catch (ZapmizerUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMissingPartnerCredentialsThrowBeforeAnyRequest()
    {
        $client = new PartnerClient(null, null, new HttpClient());

        $this->expectException(ZapmizerConnectException::class);

        $client->createSession('http://app.test/cb', 'state');
    }

    public function testContainerResolvesClientWithConfig()
    {
        config()->set('zapmizer.partner.id', 'cfg-id');
        config()->set('zapmizer.partner.secret', 'cfg-secret');
        config()->set('zapmizer.base_uri', 'http://zap.test/api/');

        $this->assertEquals('http://zap.test/api', app(PartnerClient::class)->getApiBaseUri());
    }

    public function testARedirectIsRefusedInsteadOfFollowed()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(302, ['Location' => 'http://localhost/login'], ''),
        ]));

        try {
            $client->exchangeCode('code');
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertStringContainsString('302', $exception->getMessage());
        }

        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }
}
