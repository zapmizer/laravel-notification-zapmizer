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

        return new VerificationClient('test-token', new HttpClient(['handler' => $stack]));
    }

    public function testCreateVerificationReturnsIdentifierHostedPageLinkAndState()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode([
                'data' => [
                    'id' => 'ver_123',
                    'status' => 'pending',
                    'url' => 'https://app.zapmizer.com/verify/ver_123',
                ],
            ])),
        ]));

        $verification = $client->create('+5511999999999');

        $this->assertInstanceOf(Verification::class, $verification);
        $this->assertEquals('ver_123', $verification->id);
        $this->assertEquals('pending', $verification->status);
        $this->assertEquals('https://app.zapmizer.com/verify/ver_123', $verification->url);

        $request = $this->history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://app.zapmizer.com/api/verifications', (string) $request->getUri());
        $this->assertEquals('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertEquals(['number' => '+5511999999999'], json_decode((string) $request->getBody(), true));
    }

    public function testGetVerificationByIdentifier()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'ver_123',
                'status' => 'verified',
            ])),
        ]));

        $verification = $client->get('ver_123');

        $this->assertEquals('ver_123', $verification->id);
        $this->assertEquals('verified', $verification->status);
        $this->assertNull($verification->url);

        $request = $this->history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('https://app.zapmizer.com/api/verifications/ver_123', (string) $request->getUri());
    }

    public function testNetworkFailureBecomesTypedException()
    {
        $client = $this->makeClient(new MockHandler([
            new ConnectException('Connection timed out', new Request('POST', 'verifications')),
        ]));

        $this->expectException(VerificationConnectionFailed::class);

        $client->create('+5511999999999');
    }

    public function testErrorResponseBecomesTypedExceptionWithStatusCodeAndBody()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(422, [], json_encode(['message' => 'Invalid phone number'])),
        ]));

        try {
            $client->create('not-a-number');
            $this->fail('Expected VerificationRequestFailed to be thrown.');
        } catch (VerificationRequestFailed $exception) {
            $this->assertEquals(422, $exception->getStatusCode());
            $this->assertStringContainsString('Invalid phone number', $exception->getMessage());
            $this->assertStringContainsString('Invalid phone number', $exception->getResponseBody());
        }
    }

    public function testServerErrorBecomesTypedException()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]));

        try {
            $client->get('ver_123');
            $this->fail('Expected VerificationRequestFailed to be thrown.');
        } catch (VerificationRequestFailed $exception) {
            $this->assertEquals(500, $exception->getStatusCode());
        }
    }

    public function testMissingTokenThrowsBeforeAnyRequest()
    {
        $client = new VerificationClient(null, new HttpClient());

        $this->expectException(ZapmizerVerificationException::class);

        $client->create('+5511999999999');
    }

    public function testTypedExceptionsShareACommonBase()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(400, [], '{}'),
        ]));

        $this->expectException(ZapmizerVerificationException::class);

        $client->create('+5511999999999');
    }

    public function testContainerResolvesClientWithConfig()
    {
        config()->set('zapmizer.api_token', 'config-token');

        $this->assertEquals('config-token', app(VerificationClient::class)->getToken());
        $this->assertEquals('runtime-token', app(VerificationClient::class, ['api_token' => 'runtime-token'])->getToken());
    }
}
