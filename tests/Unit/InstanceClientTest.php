<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\InstanceConnection;
use NotificationChannels\Zapmizer\Connect\MediaDownload;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InstanceClientTest extends TestCase
{
    use AssertsContract;

    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(MockHandler $mock): InstanceClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new InstanceClient('team-token', new GuzzleTransport(new HttpClient(['handler' => $stack])), 'http://localhost/api', '2025-06-27');
    }

    public function testConnectionMapsStates()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode(['data' => [
                'id' => 9, 'state' => 'connected', 'state_label' => 'Conectado', 'is_online' => true, 'is_up' => true,
                'qrcode' => null, 'qrcode_available_at' => null, 'qrcode_expires_at' => null, 'number' => '5581911110000',
            ]])),
            new Response(404, [], '{}'),
            new Response(401, [], '{}'),
            new Response(503, [], ''),
        ]));

        $connection = $client->connection(9);

        $this->assertInstanceOf(InstanceConnection::class, $connection);
        $this->assertTrue($connection->isConnected());
        $this->assertEquals('5581911110000', $connection->number);
        $this->assertEquals('http://localhost/api/bot-instances/9/connection', (string) $this->history[0]['request']->getUri());
        $this->assertEquals('Bearer team-token', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertEquals('2025-06-27', $this->history[0]['request']->getHeaderLine('api-version'));

        $this->expectExceptionInOrder([InstanceGoneException::class, ZapmizerUnauthorizedException::class, ZapmizerUnavailableException::class], fn () => $client->connection(9));
    }

    public function testCreateWebhookReturnsTheSecretOnce()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(201, [], json_encode(['data' => ['id' => 42, 'secret' => 'whsec_x']])),
        ]));

        $registration = $client->createWebhook('http://app.test/zapmizer/webhook');

        $this->assertEquals(42, $registration->id);
        $this->assertEquals('whsec_x', $registration->secret);
        $this->assertNull($registration->previousSecret);
        $this->assertEquals(
            ['url' => 'http://app.test/zapmizer/webhook', 'enabled' => true],
            json_decode((string) $this->history[0]['request']->getBody(), true)
        );
    }

    public function testRotateWebhookSecretReturnsBoth()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode(['data' => ['id' => 42, 'secret' => 'new', 'previous_secret' => 'old']])),
        ]));

        $registration = $client->rotateWebhookSecret(42);

        $this->assertEquals(['new', 'old'], [$registration->secret, $registration->previousSecret]);
        $this->assertEquals('http://localhost/api/webhooks/42/secret', (string) $this->history[0]['request']->getUri());
    }

    public function testTokenIsMandatory()
    {
        // No silent fallback to the single-tenant token: resolving the client
        // from the container without one is a hard error.
        $this->expectException(ZapmizerUnauthorizedException::class);

        $this->app->make(InstanceClient::class);
    }

    public function testDeleteWebhookTreats404AsDone()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, [], json_encode(['message' => 'Webhook removido.'])),
            new Response(404, [], '{}'),
            new Response(403, [], '{}'),
        ]));

        $client->deleteWebhook(42);
        $client->deleteWebhook(42);
        $this->assertEquals('DELETE', $this->history[0]['request']->getMethod());
        $this->assertEquals('http://localhost/api/webhooks/42', (string) $this->history[0]['request']->getUri());

        $this->expectException(ZapmizerConnectException::class);
        $client->deleteWebhook(42);
    }

    public function testMediaAttachedIsSpooledToATemporaryFile()
    {
        $bytes = "\x89PNG\r\n\x1a\n" . random_bytes(2048);
        $client = $this->makeClient(new MockHandler([
            new Response(200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) strlen($bytes),
                'Content-Disposition' => 'attachment; filename="3EB0ABC123.png"',
                'Cache-Control' => 'private, no-store',
            ], $bytes),
        ]));

        $download = $client->media(9, 'true_5581999998888@c.us_3EB0ABC123', 1700000000);

        $this->assertInstanceOf(MediaDownload::class, $download);
        $this->assertTrue($download->isAttached());
        $this->assertEquals(MediaDownload::ATTACHED, $download->state);
        $this->assertEquals('image/png', $download->mimeType);
        $this->assertEquals('3EB0ABC123.png', $download->filename);
        $this->assertEquals(strlen($bytes), $download->size);

        $path = $download->path();
        $this->assertFileExists($path);
        $this->assertEquals($bytes, file_get_contents($path));
        $this->assertEquals($bytes, $download->contents());
        $this->assertEquals($bytes, stream_get_contents($download->stream()));

        $request = $this->history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http://localhost/api/whatsapp-messages/media', $request->getUri()->withQuery('')->__toString());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertEquals(['bot_instance_id' => '9', 'message_id' => 'true_5581999998888@c.us_3EB0ABC123', 'timestamp' => '1700000000'], $query);
        $this->assertEquals('Bearer team-token', $request->getHeaderLine('Authorization'));

        // The file is the object's: gone with it.
        unset($download);
        $this->assertFileDoesNotExist($path);
    }

    public function testMediaWithoutFilenameOrLength()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/octet-stream'], 'bytes'),
        ]));

        $download = $client->media(9, 'ABC', 1700000000);

        $this->assertNull($download->filename);
        $this->assertNull($download->size);
        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertEquals('1700000000', $query['timestamp']);
    }

    public function testMediaRejectedIsAnExceptionWithTheReason()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'The timestamp must be a date before or equal to now.',
                'errors' => ['timestamp' => ['The timestamp must be a date before or equal to now.']],
            ])),
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Media is not available for Meta Cloud instances.',
            ])),
        ]));

        try {
            $client->media(9, 'ABC', 1700000000);
            $this->fail('expected a MediaRejectedException');
        } catch (ZapmizerConnectException $exception) {
            $this->assertInstanceOf(MediaRejectedException::class, $exception);
            $this->assertStringStartsWith('The timestamp must be a date before or equal to now.', $exception->reason());
            $this->assertStringContainsString('rejected the media request', $exception->getMessage());
            $this->assertStringContainsString('before or equal to now', $exception->getMessage());
        }

        try {
            $client->media(9, 'ABC', 1700000000);
            $this->fail('expected a MediaRejectedException');
        } catch (MediaRejectedException $exception) {
            $this->assertEquals('Media is not available for Meta Cloud instances.', $exception->reason());
            $this->assertStringContainsString('Meta Cloud', $exception->getMessage());
        }
    }

    public function testMediaRateLimitedIsAnExceptionWithTheRetryAfter()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '37'], json_encode(['message' => 'Too Many Attempts.'])),
            new Response(429, ['Content-Type' => 'application/json'], json_encode(['message' => 'Too Many Attempts.'])),
        ]));

        try {
            $client->media(9, 'ABC', 1700000000);
            $this->fail('expected a MediaRateLimitedException');
        } catch (ZapmizerConnectException $exception) {
            $this->assertInstanceOf(MediaRateLimitedException::class, $exception);
            $this->assertSame(37, $exception->retryAfter());
            $this->assertStringContainsString('rate-limited', $exception->getMessage());
            $this->assertStringContainsString('60 a minute', $exception->getMessage());
            $this->assertStringContainsString('Retry in 37 s', $exception->getMessage());
        }

        try {
            $client->media(9, 'ABC', 1700000000);
            $this->fail('expected a MediaRateLimitedException');
        } catch (MediaRateLimitedException $exception) {
            $this->assertNull($exception->retryAfter());
            $this->assertStringContainsString('rate-limited', $exception->getMessage());
            $this->assertStringNotContainsString('Retry in', $exception->getMessage());
        }
    }

    public function testMediaDownloadingAndUnavailable()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'downloading'])),
            new Response(404, ['Content-Type' => 'application/json'], json_encode(['media_state' => 'unavailable'])),
            // A 404 for someone else's instance carries no media_state.
            new Response(404, ['Content-Type' => 'application/json'], json_encode(['message' => 'Not found.'])),
        ]));

        $downloading = $client->media(9, 'ABC', 1700000000);
        $this->assertTrue($downloading->isDownloading());
        $this->assertFalse($downloading->isAttached());
        $this->assertNull($downloading->mimeType);

        $unavailable = $client->media(9, 'ABC', 1700000000);
        $this->assertTrue($unavailable->isUnavailable());

        $this->assertTrue($client->media(9, 'ABC', 1700000000)->isUnavailable());

        $this->expectException(\RuntimeException::class);
        $downloading->path();
    }

    public function testMediaRefusesTheOtherAnswersLikeEveryEndpoint()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(401, [], '{}'),
            new Response(302, ['Location' => 'http://localhost/login'], ''),
            new Response(403, ['Content-Type' => 'application/json'], '{}'),
            new Response(503, [], ''),
        ]));

        $this->expectExceptionInOrder([
            ZapmizerUnauthorizedException::class,
            ZapmizerConnectException::class,
            ZapmizerConnectException::class,
            ZapmizerUnavailableException::class,
        ], fn () => $client->media(9, 'ABC', 1700000000));
    }

    public function testMediaUnexpectedJsonOnA202IsAnError()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(202, ['Content-Type' => 'text/html'], '<html>'),
        ]));

        $this->expectException(ZapmizerConnectException::class);
        $client->media(9, 'ABC', 1700000000);
    }

    public function testMediaFilenameVariants()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename*=UTF-8''extrato%20m%C3%AAs.pdf"], 'x'),
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename=plain.pdf'], 'x'),
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline'], 'x'),
        ]));

        $this->assertEquals('extrato mês.pdf', $client->media(9, 'A', 1700000000)->filename);
        $this->assertEquals('plain.pdf', $client->media(9, 'A', 1700000000)->filename);
        $this->assertNull($client->media(9, 'A', 1700000000)->filename);
    }

    /**
     * @param array<int, class-string<\Throwable>> $expected
     */
    protected function expectExceptionInOrder(array $expected, callable $call): void
    {
        foreach ($expected as $class) {
            try {
                $call();
                $this->fail("Expected {$class}.");
            } catch (\Throwable $exception) {
                $this->assertInstanceOf($class, $exception);
            }
        }
    }

    protected function callEndpoint(InstanceClient $client, string $call): mixed
    {
        return match ($call) {
            'connection' => $client->connection(9),
            'createWebhook' => $client->createWebhook('http://app.test/zapmizer/webhook'),
            'rotateWebhookSecret' => $client->rotateWebhookSecret(42),
            'deleteWebhook' => $client->deleteWebhook(42),
            'media' => $client->media(9, 'ABC', 1700000000),
        };
    }

    protected function assertRefusal(string $call, Response $response, string $class): ZapmizerApiException
    {
        $client = $this->makeClient(new MockHandler([$response]));

        try {
            $this->callEndpoint($client, $call);
        } catch (ZapmizerApiException $exception) {
            $this->assertSame($class, get_class($exception));
            $this->assertSame($response->getStatusCode(), $exception->status());

            return $exception;
        }

        $this->fail("Expected {$class}.");
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testRefusalFromTheContract(string $call, string $method, string $path, int $status, string $body, array $headers, string $class, string $reason)
    {
        $this->assertMatchesContract($method, $path, $status, $body, $headers);

        $exception = $this->assertRefusal($call, new Response($status, ['Content-Type' => 'application/json'] + $headers, $body), $class);

        $this->assertSame($reason, $exception->reason());
    }

    public static function refusalsFromTheContract(): array
    {
        return [
            'M8 connection 404' => ['connection', 'GET', '/bot-instances/{id}/connection', 404, '{"message":"No query results for model."}', [], InstanceGoneException::class, 'No query results for model.'],
            'M10 connection 401' => ['connection', 'GET', '/bot-instances/{id}/connection', 401, '{"message":"Unauthenticated."}', [], ZapmizerUnauthorizedException::class, 'Unauthenticated.'],
            'M13 media 422' => ['media', 'GET', '/whatsapp-messages/media', 422, '{"message":"The timestamp field must be a date before or equal to now.","errors":{"timestamp":["The timestamp field must be a date before or equal to now."]}}', [], MediaRejectedException::class, 'The timestamp field must be a date before or equal to now. The timestamp field must be a date before or equal to now.'],
            'M13 media 429' => ['media', 'GET', '/whatsapp-messages/media', 429, '{"message":"Too Many Attempts."}', ['Retry-After' => '37'], MediaRateLimitedException::class, 'Too Many Attempts.'],
            'media 401' => ['media', 'GET', '/whatsapp-messages/media', 401, '{"message":"Unauthenticated."}', [], ZapmizerUnauthorizedException::class, 'Unauthenticated.'],
        ];
    }

    /** @dataProvider refusalsOutsideTheContract */
    #[DataProvider('refusalsOutsideTheContract')]
    public function testRefusalOutsideTheContract(string $call, Response $response, string $class)
    {
        $this->assertRefusal($call, $response, $class);
    }

    public static function refusalsOutsideTheContract(): array
    {
        $json = ['Content-Type' => 'application/json'];

        return [
            'M9 connection 429' => ['connection', new Response(429, $json + ['Retry-After' => '5'], '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class],
            'M9 connection 403' => ['connection', new Response(403, $json, '{"message":"This action is unauthorized."}'), ZapmizerApiException::class],
            'M9 connection 422' => ['connection', new Response(422, $json, '{"message":"Invalid.","errors":{"id":["Invalid."]}}'), ZapmizerApiException::class],
            'M11 createWebhook 422' => ['createWebhook', new Response(422, $json, '{"message":"The url field must be a valid URL.","errors":{"url":["The url field must be a valid URL."]}}'), ZapmizerApiException::class],
            'createWebhook 401' => ['createWebhook', new Response(401, $json, '{"message":"Unauthenticated."}'), ZapmizerUnauthorizedException::class],
            'rotateWebhookSecret 404' => ['rotateWebhookSecret', new Response(404, $json, '{"message":"Not Found"}'), ZapmizerApiException::class],
            'rotateWebhookSecret 429' => ['rotateWebhookSecret', new Response(429, $json, '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class],
            'deleteWebhook 403' => ['deleteWebhook', new Response(403, $json, '{"message":"Forbidden."}'), ZapmizerApiException::class],
            'deleteWebhook 429' => ['deleteWebhook', new Response(429, $json, '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class],
            'M14 media 403' => ['media', new Response(403, $json, '{"message":"This connection does not grant this number."}'), ZapmizerApiException::class],
            'media 409' => ['media', new Response(409, $json, '{"message":"Conflict."}'), ZapmizerApiException::class],
        ];
    }

    public function testM9ConnectionRateLimitCarriesTheRetryAfter()
    {
        $exception = $this->assertRefusal('connection', new Response(429, ['Retry-After' => '5'], '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class);

        $this->assertSame(5, $exception->retryAfter());
    }

    public function testM11WebhookRefusalKeepsTheFieldErrors()
    {
        $exception = $this->assertRefusal('createWebhook', new Response(422, ['Content-Type' => 'application/json'], '{"message":"Invalid.","errors":{"url":["The url field must be a valid URL."]}}'), ZapmizerApiException::class);

        $this->assertSame(['url' => ['The url field must be a valid URL.']], $exception->errors());
        $this->assertSame('Zapmizer refused the request (HTTP 422): Invalid. The url field must be a valid URL.', $exception->getMessage());
    }

    public function testMediaRetryAfterThatIsNotANumberIsNull()
    {
        $exception = $this->assertRefusal('media', new Response(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], '{"message":"Too Many Attempts."}'), MediaRateLimitedException::class);

        $this->assertNull($exception->retryAfter());
        $this->assertStringNotContainsString('Retry in', $exception->getMessage());
    }

    public function testMediaRejectionWithHtmlCutsTheReasonAt500Characters()
    {
        $html = '<html>' . str_repeat('x', 800) . '</html>';

        $exception = $this->assertRefusal('media', new Response(422, ['Content-Type' => 'text/html'], $html), MediaRejectedException::class);

        $this->assertSame(substr($html, 0, 500), $exception->reason());
    }

    public function testAMediaRefusalWithoutATemporaryFileReadsTheLiveBody()
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(422, ['Content-Type' => 'application/json'], '{"message":"Media is not available for Meta Cloud instances."}'),
        ]));
        $client = new class ('team-token', new GuzzleTransport(new HttpClient(['handler' => $stack])), 'http://localhost/api') extends InstanceClient {
            protected function temporaryMediaPath(): ?string
            {
                return null;
            }
        };

        try {
            $client->media(9, 'ABC', 1700000000);
            $this->fail('Expected MediaRejectedException.');
        } catch (MediaRejectedException $exception) {
            $this->assertSame('Media is not available for Meta Cloud instances.', $exception->reason());
        }
    }

    public function testARedirectIsRefusedInsteadOfFollowed()
    {
        $client = $this->makeClient(new MockHandler([
            new Response(302, ['Location' => 'http://localhost/login'], ''),
        ]));

        try {
            $client->connection(9);
            $this->fail('Expected ZapmizerConnectException.');
        } catch (ZapmizerConnectException $exception) {
            $this->assertStringContainsString('302', $exception->getMessage());
        }

        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }
}
