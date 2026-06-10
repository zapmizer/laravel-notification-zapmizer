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
use NotificationChannels\Zapmizer\Verification;
use NotificationChannels\Zapmizer\VerificationClient;

class VerificationClientTest extends TestCase
{
    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): VerificationClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new VerificationClient(
            'pk_test',
            new HttpClient(['handler' => $stack]),
            'http://localhost/api',
            null,
            'http://localhost:8000',
        );
    }

    public function testCreateVerificationReturnsSessionWithWaLink()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode([
                'number' => '5511999999999',
                'status' => 'pending',
                'verified_at' => null,
                'expires_at' => '2026-06-10T00:00:00.000000Z',
                'failure_reason' => null,
                'client_reference' => '42',
                'wa_link' => 'https://wa.me/5581999999999?text=ABC123',
                'code_length' => 6,
            ])),
        ]));

        $verification = $client->create('+55 11 99999-9999', clientReference: '42');

        $this->assertInstanceOf(Verification::class, $verification);
        $this->assertEquals('5511999999999', $verification->number);
        $this->assertEquals('pending', $verification->status);
        $this->assertEquals('https://wa.me/5581999999999?text=ABC123', $verification->waLink);
        $this->assertEquals(6, $verification->codeLength);
        $this->assertEquals('42', $verification->clientReference);
        $this->assertFalse($verification->isVerified());
        $this->assertFalse($verification->isTerminal());

        $request = $this->history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://localhost/api/verify-number', (string) $request->getUri());
        $this->assertEquals('pk_test', $request->getHeaderLine('X-Publishable-Key'));
        $this->assertEquals('http://localhost:8000', $request->getHeaderLine('Origin'));
        $this->assertEquals(
            ['number' => '+55 11 99999-9999', 'client_reference' => '42'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testCreateWhileResolvingHasNoWaLink()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode([
                'number' => '5511999999999',
                'status' => 'resolving',
                'code_length' => 6,
            ])),
        ]));

        $verification = $client->create('5511999999999');

        $this->assertEquals('resolving', $verification->status);
        $this->assertNull($verification->waLink);
    }

    public function testGetVerificationByNumber()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode([
                'number' => '5511999999999',
                'status' => 'verified',
                'verified_at' => '2026-06-10T00:00:00.000000Z',
            ])),
        ]));

        $verification = $client->get('5511999999999');

        $this->assertEquals('verified', $verification->status);
        $this->assertTrue($verification->isVerified());
        $this->assertTrue($verification->isTerminal());

        $request = $this->history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http://localhost/api/verify-number/5511999999999', (string) $request->getUri());
    }

    public function testConfirmSendsCodeAndReturnsSession()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode([
                'number' => '5511999999999',
                'status' => 'verified',
                'verified_at' => '2026-06-10T00:00:00.000000Z',
            ])),
        ]));

        $verification = $client->confirm('5511999999999', '123456');

        $this->assertTrue($verification->isVerified());

        $request = $this->history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://localhost/api/verify-number/5511999999999/confirm', (string) $request->getUri());
        $this->assertEquals(['code' => '123456'], json_decode((string) $request->getBody(), true));
    }

    public function testNetworkFailureBecomesTypedException()
    {
        $client = $this->makeClient(new MockHandler([
            new ConnectException('Connection timed out', new Request('POST', 'verify-number')),
        ]));

        $this->expectException(VerificationConnectionFailed::class);

        $client->create('5511999999999');
    }

    public function testErrorResponseBecomesTypedExceptionWithStatusCodeAndBody()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(422, [], json_encode(['message' => 'Esse número não tem WhatsApp.'])),
        ]));

        try {
            $client->create('not-a-number');
            $this->fail('Expected VerificationRequestFailed to be thrown.');
        } catch (VerificationRequestFailed $exception) {
            $this->assertEquals(422, $exception->getStatusCode());
            $this->assertStringContainsString('Esse número não tem WhatsApp.', $exception->getMessage());
        }
    }

    public function testWrongCodeBecomesTypedException()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(422, [], json_encode(['message' => 'Código inválido.'])),
        ]));

        try {
            $client->confirm('5511999999999', '000000');
            $this->fail('Expected VerificationRequestFailed to be thrown.');
        } catch (VerificationRequestFailed $exception) {
            $this->assertEquals(422, $exception->getStatusCode());
        }
    }

    public function testServerErrorBecomesTypedException()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]));

        try {
            $client->get('5511999999999');
            $this->fail('Expected VerificationRequestFailed to be thrown.');
        } catch (VerificationRequestFailed $exception) {
            $this->assertEquals(500, $exception->getStatusCode());
        }
    }

    public function testMissingTokenThrowsBeforeAnyRequest()
    {
        $client = new VerificationClient(null, new HttpClient());

        $this->expectException(ZapmizerVerificationException::class);

        $client->create('5511999999999');
    }

    public function testTypedExceptionsShareACommonBase()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(400, [], '{}'),
        ]));

        $this->expectException(ZapmizerVerificationException::class);

        $client->create('5511999999999');
    }

    public function testContainerResolvesClientWithConfig()
    {
        config()->set('zapmizer.api_token', 'messages-token');
        config()->set('zapmizer.publishable_key', 'pk_config');
        config()->set('app.url', 'http://demo.test');

        // The verify-number client uses the publishable key, never the messages API token.
        $this->assertEquals('pk_config', app(VerificationClient::class)->getPublishableKey());
        $this->assertEquals('http://demo.test', app(VerificationClient::class)->getOrigin());
        $this->assertEquals('pk_runtime', app(VerificationClient::class, ['publishable_key' => 'pk_runtime'])->getPublishableKey());

        config()->set('zapmizer.origin', 'http://configured.test');
        $this->assertEquals('http://configured.test', app(VerificationClient::class)->getOrigin());
    }
}
