<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Exceptions\VerificationConnectionFailed;
use NotificationChannels\Zapmizer\Exceptions\VerificationRequestFailed;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\VerificationClient;
use NotificationChannels\Zapmizer\VerificationResult;
use NotificationChannels\Zapmizer\VerificationSession;

class VerificationClientTest extends TestCase
{
    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): VerificationClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new VerificationClient(
            'team-api-token',
            new HttpClient(['handler' => $stack]),
            'http://localhost/api',
        );
    }

    public function testCreateSessionReturnsHostedPageUrl()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode([
                'url' => 'http://localhost/verify-number/1?number=5511999999999&expires=123&signature=abc',
                'expires_at' => '2026-06-10T01:00:00.000000Z',
            ])),
        ]));

        $session = $client->createSession('5511999999999', '5581999999999', 900);

        $this->assertInstanceOf(VerificationSession::class, $session);
        $this->assertStringContainsString('/verify-number/1', $session->url);
        $this->assertEquals('2026-06-10T01:00:00.000000Z', $session->expiresAt);

        $request = $this->history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://localhost/api/verify-number/sessions', (string) $request->getUri());
        $this->assertEquals('Bearer team-api-token', $request->getHeaderLine('Authorization'));
        $this->assertEquals(
            // number prefills the hosted page; from picks the receiving bot
            ['number' => '5511999999999', 'from' => '5581999999999', 'expires_in' => 900],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testCreateSessionWithNoArgumentsSendsEmptyBody()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode(['url' => 'http://localhost/verify-number/1?signature=abc'])),
        ]));

        $client->createSession();

        $this->assertEquals([], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testConfirmVerifiedReturnsCanonicalNumberAndBot()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode([
                'status' => 'verified',
                'number' => '5511999999999',
                'from' => '5581999999999',
                'attempts_left' => null,
            ])),
        ]));

        $result = $client->confirm('+55 11 99999-9999', '123456');

        $this->assertInstanceOf(VerificationResult::class, $result);
        $this->assertTrue($result->isVerified());
        $this->assertEquals('5511999999999', $result->number);
        $this->assertEquals('5581999999999', $result->from);

        $request = $this->history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://localhost/api/verify-number/confirm', (string) $request->getUri());
        $this->assertEquals(
            ['number' => '+55 11 99999-9999', 'code' => '123456'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testConfirmInvalidCarriesAttemptsLeft()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode([
                'status' => 'invalid',
                'number' => null,
                'from' => null,
                'attempts_left' => 3,
            ])),
        ]));

        $result = $client->confirm('5511999999999', '000000');

        $this->assertTrue($result->isInvalid());
        $this->assertFalse($result->isVerified());
        $this->assertEquals(3, $result->attemptsLeft);
    }

    public function testConfirmNotFoundIsARetryableResult()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode([
                'status' => 'not_found',
                'number' => null,
                'from' => null,
                'attempts_left' => null,
            ])),
        ]));

        $result = $client->confirm('5511999999999', '123456');

        $this->assertTrue($result->isNotFound());
        $this->assertFalse($result->isVerified());
    }

    public function testNetworkFailureBecomesTypedException()
    {
        $client = $this->makeClient(new MockHandler([
            new ConnectException('Connection timed out', new Request('POST', 'verify-number/sessions')),
        ]));

        $this->expectException(VerificationConnectionFailed::class);

        $client->createSession('5511999999999');
    }

    public function testNoOnlineBotBecomesTypedException()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(503, [], json_encode(['message' => 'Nenhum número de WhatsApp conectado pra receber a verificação.'])),
        ]));

        try {
            $client->createSession('5511999999999');
            $this->fail('Expected VerificationRequestFailed to be thrown.');
        } catch (VerificationRequestFailed $exception) {
            $this->assertEquals(503, $exception->getStatusCode());
            $this->assertStringContainsString('Nenhum número de WhatsApp conectado', $exception->getMessage());
        }
    }

    public function testUnknownFromNumberBecomesTypedException()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(422, [], json_encode(['message' => 'Nenhuma conta de WhatsApp da equipe com esse número.'])),
        ]));

        try {
            $client->createSession('5511999999999', from: '5599999999999');
            $this->fail('Expected VerificationRequestFailed to be thrown.');
        } catch (VerificationRequestFailed $exception) {
            $this->assertEquals(422, $exception->getStatusCode());
        }
    }

    public function testMissingTokenThrowsBeforeAnyRequest()
    {
        $client = new VerificationClient(null, new HttpClient());

        $this->expectException(ZapmizerVerificationException::class);

        $client->createSession('5511999999999');
    }

    public function testTypedExceptionsShareACommonBase()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(400, [], '{}'),
        ]));

        $this->expectException(ZapmizerVerificationException::class);

        $client->createSession('5511999999999');
    }

    public function testContainerResolvesClientWithConfig()
    {
        config()->set('zapmizer.api_token', 'config-token');

        $this->assertEquals('config-token', app(VerificationClient::class)->getToken());
        $this->assertEquals('runtime-token', app(VerificationClient::class, ['api_token' => 'runtime-token'])->getToken());
    }
}
