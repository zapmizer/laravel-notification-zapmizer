<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Connect\ZapmizerApi;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Fixtures\FailingWriteStream;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\Fixtures\SpyingInstanceClient;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

class InstanceClientMediaTest extends TestCase
{
    use FakesZapmizerHttp;

    protected array $history = [];

    protected array $spies = [];

    protected function spyClient(Transport $transport): SpyingInstanceClient
    {
        return $this->spies[] = new SpyingInstanceClient('tok', $transport, 'http://zap.test/api');
    }

    protected function instanceClient(?float $connectTimeout = null, ?float $timeout = null): InstanceClient
    {
        return $this->spyClient(new LaravelHttpTransport($connectTimeout, $timeout));
    }

    protected function client(array $queue, ?float $connectTimeout = null, ?float $timeout = null): InstanceClient
    {
        $this->fakeZapmizer(new MockHandler($queue));

        return $this->instanceClient($connectTimeout, $timeout);
    }

    protected function createdPaths(): array
    {
        return array_merge(...array_map(fn ($spy) => $spy->createdPaths, $this->spies)) ?: [];
    }

    protected function assertNoCreatedFileRemains(array $keep = []): void
    {
        foreach ($this->createdPaths() as $path) {
            if (!in_array($path, $keep, true)) {
                $this->assertFileDoesNotExist($path);
            }
        }
    }

    protected function caught(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    public function testC24TheMediaCallCarriesTheTransferTimeouts()
    {
        $client = $this->client([new Response(200, ['Content-Type' => 'image/png', 'Content-Length' => '5'], 'bytes')], 5.0, 7.0);

        $download = $client->media(9, 'ABC', 1700000000);

        $this->assertTrue($download->isAttached());
        $this->assertSame('bytes', stream_get_contents($download->stream()));
        $this->assertSame(ZapmizerApi::TRANSFER_CONNECT_TIMEOUT, $this->history[0]['options']['connect_timeout']);
        $this->assertSame(ZapmizerApi::TRANSFER_TIMEOUT, $this->history[0]['options']['timeout']);
        $this->assertArrayNotHasKey('stream', $this->history[0]['options']);
        $this->assertArrayNotHasKey('sink', $this->history[0]['options']);
        $this->assertSame(60, ZapmizerApi::TRANSFER_CONNECT_TIMEOUT);
        $this->assertSame(600, ZapmizerApi::TRANSFER_TIMEOUT);
    }

    public function testC28AndC29EveryAnswerButTheAttachedLeavesNoFile()
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'image/png'], 'bytes'),
            new Response(202, ['Content-Type' => 'application/json'], '{"media_state":"downloading"}'),
            new Response(404, ['Content-Type' => 'application/json'], '{"media_state":"unavailable"}'),
            new Response(422, ['Content-Type' => 'application/json'], '{"message":"bad"}'),
            new Response(429, ['Retry-After' => '7']),
            new Response(401, [], '{}'),
            new Response(302, ['Location' => 'http://zap.test/login']),
            new Response(503, [], ''),
        ]);

        $download = $client->media(9, 'ABC', 1700000000);
        $this->assertSame('bytes', stream_get_contents($download->stream()));
        unset($download);

        $this->assertTrue($client->media(9, 'ABC', 1700000000)->isDownloading());
        $this->assertTrue($client->media(9, 'ABC', 1700000000)->isUnavailable());

        $rejected = $this->caught(fn () => $client->media(9, 'ABC', 1700000000));
        $this->assertInstanceOf(MediaRejectedException::class, $rejected);
        $this->assertStringContainsString('bad', $rejected->reason());

        $limited = $this->caught(fn () => $client->media(9, 'ABC', 1700000000));
        $this->assertInstanceOf(MediaRateLimitedException::class, $limited);
        $this->assertSame(7, $limited->retryAfter());

        $this->assertInstanceOf(ZapmizerUnauthorizedException::class, $this->caught(fn () => $client->media(9, 'ABC', 1700000000)));
        $this->assertInstanceOf(ZapmizerConnectException::class, $this->caught(fn () => $client->media(9, 'ABC', 1700000000)));
        $this->assertInstanceOf(ZapmizerUnavailableException::class, $this->caught(fn () => $client->media(9, 'ABC', 1700000000)));

        $this->assertNotEmpty($this->createdPaths());
        $this->assertNoCreatedFileRemains();
    }

    public function testC30TransportFailureMidDownloadIsUnavailableWithoutFile()
    {
        $client = $this->client([new RequestException('cut', new Request('GET', 'http://zap.test'), new Response(200))]);

        $this->assertInstanceOf(ZapmizerUnavailableException::class, $this->caught(fn () => $client->media(9, 'ABC', 1700000000)));
        $this->assertSame([], $this->createdPaths());
        $this->assertNoCreatedFileRemains();
    }

    public function testAReadFailureMidCopyIsUnavailableWithoutFile()
    {
        $reads = 0;
        $body = new PumpStream(function () use (&$reads) {
            if ($reads++ === 0) {
                return str_repeat('a', 65536);
            }

            throw new RuntimeException('cut');
        });
        $transport = new RecordingTransport(new Response(200, [], $body));

        $exception = $this->caught(fn () => $this->spyClient($transport)->media(9, 'ABC', 1700000000));

        $this->assertInstanceOf(ZapmizerUnavailableException::class, $exception);
        $this->assertSame('cut', $exception->getPrevious()->getMessage());
        $this->assertNotEmpty($this->createdPaths());
        $this->assertNoCreatedFileRemains();
    }

    public function testAWriteFailureMidCopyIsUnavailableWithoutFile()
    {
        stream_wrapper_register('zapmizerfull', FailingWriteStream::class);
        FailingWriteStream::$unlinked = [];

        try {
            $client = new class ('tok', new LaravelHttpTransport(), 'http://zap.test/api') extends InstanceClient {
                protected function temporaryMediaPath(): ?string
                {
                    return 'zapmizerfull://media';
                }
            };
            $this->fakeZapmizer(new MockHandler([new Response(200, [], 'bytes')]));

            $exception = $this->caught(fn () => $client->media(9, 'ABC', 1700000000));

            $this->assertInstanceOf(ZapmizerUnavailableException::class, $exception);
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame(['zapmizerfull://media'], FailingWriteStream::$unlinked);
        } finally {
            stream_wrapper_unregister('zapmizerfull');
        }
    }

    public function testAnUnopenableTemporaryFileIsUnavailable()
    {
        $directory = sys_get_temp_dir() . '/zapmizer-dir-' . uniqid();
        mkdir($directory);

        try {
            $client = new class ('tok', new LaravelHttpTransport(), 'http://zap.test/api') extends InstanceClient {
                public string $directory;

                protected function temporaryMediaPath(): ?string
                {
                    return $this->directory;
                }
            };
            $client->directory = $directory;
            $this->fakeZapmizer(new MockHandler([new Response(200, [], 'bytes')]));

            $this->assertInstanceOf(ZapmizerUnavailableException::class, $this->caught(fn () => $client->media(9, 'ABC', 1700000000)));
            $this->assertDirectoryExists($directory);
        } finally {
            @rmdir($directory);
        }
    }

    public function testC30BodyShorterThanContentLengthIsUnavailable()
    {
        $client = $this->client([
            new Response(200, ['Content-Length' => '100'], '0123456789'),
            new Response(200, ['Content-Length' => '100'], ''),
        ]);

        $this->assertInstanceOf(ZapmizerUnavailableException::class, $this->caught(fn () => $client->media(9, 'ABC', 1700000000)));
        $this->assertInstanceOf(ZapmizerUnavailableException::class, $this->caught(fn () => $client->media(9, 'ABC', 1700000000)));
        $this->assertNotEmpty($this->createdPaths());
        $this->assertNoCreatedFileRemains();
    }

    public function testChunkedResponseIgnoresContentLength()
    {
        $client = $this->client([new Response(200, ['Content-Length' => '100', 'Transfer-Encoding' => 'chunked'], '0123456789')]);

        $download = $client->media(9, 'ABC', 1700000000);

        $this->assertSame('0123456789', stream_get_contents($download->stream()));
    }

    public function testC41EmptyBodyWithoutContentLengthIsAttachedEmpty()
    {
        $download = $this->client([new Response(200, [], '')])->media(9, 'ABC', 1700000000);

        $this->assertTrue($download->isAttached());
        $this->assertSame('', stream_get_contents($download->stream()));
        $this->assertSame(0, $download->size);
    }

    public function testAReusedFakeResponseIsReadFromTheStart()
    {
        $response = new Response(200, ['Content-Length' => '5'], 'bytes');
        $response->getBody()->getContents();
        $client = $this->client([$response, $response]);

        $this->assertSame('bytes', stream_get_contents($client->media(9, 'ABC', 1700000000)->stream()));
        $this->assertSame('bytes', stream_get_contents($client->media(9, 'ABC', 1700000000)->stream()));
        $this->assertTrue($response->getBody()->isReadable());
    }

    public function testTheSameFakedMediaCanBeFetchedTwice()
    {
        Http::fake(['zap.test/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);

        $client = $this->instanceClient();

        $this->assertSame('bytes', stream_get_contents($client->media(9, 'A', 1700000000)->stream()));
        $this->assertSame('bytes', stream_get_contents($client->media(9, 'A', 1700000000)->stream()));
    }

    public function testANonSeekableBodyOfUnknownSizeIsReadFromWhereItIs()
    {
        $chunks = ['abc'];
        $body = new PumpStream(function () use (&$chunks) {
            return array_shift($chunks) ?? false;
        });
        $transport = new RecordingTransport(new Response(200, [], $body));

        $download = $this->spyClient($transport)->media(9, 'ABC', 1700000000);

        $this->assertSame('abc', stream_get_contents($download->stream()));
    }

    public function testC27BytesLostByTheTransportAreUnexpected()
    {
        $transport = new RecordingTransport(new Response(200, ['Content-Length' => '5'], new PumpStream(fn () => false)));

        $exception = $this->caught(fn () => $this->spyClient($transport)->media(9, 'ABC', 1700000000));

        $this->assertInstanceOf(ZapmizerConnectException::class, $exception);
        $this->assertNotInstanceOf(ZapmizerUnavailableException::class, $exception, 'lost bytes are not a cut body');
        $this->assertStringContainsString('lost', $exception->getMessage());
        $this->assertNoCreatedFileRemains();
    }

    public function testDetachedBodyIsLostNotARawStreamError()
    {
        $response = new Response(200, ['Content-Length' => '5'], 'bytes');
        $response->getBody()->close();
        $transport = new RecordingTransport($response);

        $exception = $this->caught(fn () => $this->spyClient($transport)->media(9, 'ABC', 1700000000));

        $this->assertInstanceOf(ZapmizerConnectException::class, $exception);
        $this->assertNotInstanceOf(ZapmizerUnavailableException::class, $exception, 'a detached body is not a cut body');
        $this->assertStringContainsString('lost', $exception->getMessage());
        $this->assertNoCreatedFileRemains();
    }

    public function testC31RepeatedDownloadingLeavesOnlyTheFinalFile()
    {
        $client = $this->client([
            new Response(202, ['Content-Type' => 'application/json'], '{"media_state":"downloading"}'),
            new Response(202, ['Content-Type' => 'application/json'], '{"media_state":"downloading"}'),
            new Response(200, ['Content-Type' => 'image/png'], 'bytes'),
        ]);

        $client->media(9, 'ABC', 1700000000);
        $client->media(9, 'ABC', 1700000000);
        $download = $client->media(9, 'ABC', 1700000000);

        $this->assertCount(1, $this->createdPaths());
        $this->assertFileExists($download->path());
        $this->assertContains($download->path(), $this->createdPaths());
        $this->assertNoCreatedFileRemains([$download->path()]);
        $this->assertSame('bytes', stream_get_contents($download->stream()));
    }

    public function testC32WithoutATemporaryFileOnlyTheAttachedAnswerFails()
    {
        $client = new class ('tok', new LaravelHttpTransport(), 'http://zap.test/api') extends InstanceClient {
            protected function temporaryMediaPath(): ?string
            {
                return null;
            }
        };
        $this->fakeZapmizer(new MockHandler([
            new Response(202, ['Content-Type' => 'application/json'], '{"media_state":"downloading"}'),
            new Response(200, ['Content-Type' => 'image/png'], 'bytes'),
        ]));

        $downloading = $client->media(9, 'ABC', 1700000000);
        $this->assertFalse($downloading->isAttached());
        $this->assertArrayNotHasKey('stream', $this->history[0]['options']);
        $this->assertArrayNotHasKey('sink', $this->history[0]['options']);

        $this->expectException(ZapmizerUnavailableException::class);

        $client->media(9, 'ABC', 1700000000);
    }

    public function testSilencedTempnamDoesNotRaiseUnderTheLaravelErrorHandler()
    {
        $path = @tempnam('/nonexistent-zapmizer-dir', 'zapmizer-media-');

        $this->assertIsString($path);
        @unlink($path);
    }

    public function testTheSameFakedAnswerCanBeFetchedTwice()
    {
        Http::fake(['zap.test/*' => Http::response(['media_state' => 'downloading'], 202)]);

        $client = $this->instanceClient();

        $this->assertFalse($client->media(9, 'A', 1700000000)->isAttached());
        $this->assertFalse($client->media(9, 'A', 1700000000)->isAttached());
    }

    public function testC25GlobalMiddlewareReadingTheBodyDoesNotEmptyTheMedia()
    {
        if (!method_exists(Factory::class, 'globalMiddleware')) {
            $this->markTestSkipped('globalMiddleware exists from Laravel 10 on.');
        }

        Http::globalMiddleware(fn (callable $handler) => fn ($request, array $options) => $handler($request, $options)->then(function ($response) {
            (string) $response->getBody();

            return $response;
        }));
        Http::fake(['zap.test/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);

        $this->assertSame('bytes', stream_get_contents($this->instanceClient()->media(9, 'A', 1700000000)->stream()));
    }

    public function testStrayMediaRequestPassesThroughAndLeavesNoFile()
    {
        if (!method_exists(Factory::class, 'preventStrayRequests')) {
            $this->markTestSkipped('preventStrayRequests exists from Laravel 9 on.');
        }

        Http::preventStrayRequests();
        Http::fake(['other.test/*' => Http::response('{}')]);

        $caught = $this->caught(fn () => $this->instanceClient()->media(9, 'A', 1700000000));

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertNotInstanceOf(AssertionFailedError::class, $caught);
        $this->assertSame([], $this->createdPaths());
        $this->assertNoCreatedFileRemains();
    }

    public function testABodyOverTwoMegabytesIsCopiedWhole()
    {
        $bytes = random_bytes(3 * 1024 * 1024);
        $client = $this->client([new Response(200, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) strlen($bytes)], $bytes)]);

        $download = $client->media(9, 'ABC', 1700000000);

        $this->assertSame(strlen($bytes), $download->size);
        $this->assertSame(md5($bytes), md5_file($download->path()));
    }

    public function testASubclassOverridingSpoolWithThe03SignatureStillWorks()
    {
        $client = new class ('tok', new LaravelHttpTransport(), 'http://zap.test/api') extends InstanceClient {
            protected function spool(StreamInterface $body): string
            {
                return parent::spool($body);
            }
        };
        $this->fakeZapmizer(new MockHandler([new Response(200, [], 'bytes')]));

        $this->assertSame('bytes', stream_get_contents($client->media(9, 'ABC', 1700000000)->stream()));
    }
}
