<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\MediaDownload;
use NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

class InstanceClientMediaTest extends TestCase
{
    protected array $history = [];

    protected function client(array $queue, ?float $connectTimeout = null, ?float $timeout = null): InstanceClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new InstanceClient('tok', new GuzzleTransport(new HttpClient(['handler' => $stack]), $connectTimeout, $timeout), 'http://zap.test/api');
    }

    protected function sinkOf(int $call): string
    {
        return $this->history[$call]['options']['sink'];
    }

    public function testC24AttachedGoesStraightToTheSinkWithMediaLimits()
    {
        $client = $this->client([new Response(200, ['Content-Type' => 'image/png', 'Content-Length' => '5'], 'bytes')], 5.0, 7.0);

        $download = $client->media(9, 'ABC', 1700000000);

        $this->assertTrue($download->isAttached());
        $this->assertSame('bytes', stream_get_contents($download->stream()));
        $this->assertSame(InstanceClient::MEDIA_CONNECT_TIMEOUT, $this->history[0]['options']['connect_timeout']);
        $this->assertSame(InstanceClient::MEDIA_TIMEOUT, $this->history[0]['options']['timeout']);
        $this->assertArrayNotHasKey('stream', $this->history[0]['options']);
    }

    public function testC28AndC29NoStatusLeavesATemporaryFile()
    {
        $cases = [
            [new Response(202, ['Content-Type' => 'application/json'], '{"media_state":"downloading"}'), null],
            [new Response(404, ['Content-Type' => 'application/json'], '{"media_state":"unavailable"}'), null],
            [new Response(422, ['Content-Type' => 'application/json'], '{"message":"bad"}'), MediaRejectedException::class],
            [new Response(429, ['Retry-After' => '7']), MediaRateLimitedException::class],
            [new Response(401, [], '{}'), ZapmizerUnauthorizedException::class],
            [new Response(302, ['Location' => 'http://zap.test/login']), ZapmizerConnectException::class],
            [new Response(503, [], ''), ZapmizerUnavailableException::class],
        ];

        foreach ($cases as $i => [$response, $expected]) {
            $client = $this->client([$response]);

            try {
                $client->media(9, 'ABC', 1700000000);
                $this->assertNull($expected, "case {$i} should have thrown");
            } catch (Throwable $exception) {
                $this->assertInstanceOf($expected, $exception, "case {$i}");
            }

            $this->assertFileDoesNotExist($this->sinkOf($i), "case {$i} left a file");
        }
    }

    public function testC30TransportFailureMidDownloadIsUnavailableWithoutFile()
    {
        $client = $this->client([new RequestException('cut', new Request('GET', 'http://zap.test'), new Response(200))]);

        try {
            $client->media(9, 'ABC', 1700000000);
            $this->fail('expected ZapmizerUnavailableException');
        } catch (ZapmizerUnavailableException $exception) {
            $this->assertFileDoesNotExist($this->sinkOf(0));
        }
    }

    public function testC30BodyShorterThanContentLengthIsUnavailable()
    {
        foreach (['0123456789', ''] as $i => $body) {
            $client = $this->client([new Response(200, ['Content-Length' => '100'], $body)]);

            try {
                $client->media(9, 'ABC', 1700000000);
                $this->fail('expected ZapmizerUnavailableException');
            } catch (ZapmizerUnavailableException $exception) {
                $this->assertFileDoesNotExist($this->sinkOf($i));
            }
        }
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
    }

    public function testC26TransportIgnoringSinkStillDeliversABodyAlreadyRead()
    {
        $response = new Response(200, ['Content-Length' => '5'], 'bytes');
        $response->getBody()->getContents();
        $transport = new RecordingTransport($response);

        $download = (new InstanceClient('tok', $transport, 'http://zap.test/api'))->media(9, 'ABC', 1700000000);

        $this->assertSame('bytes', stream_get_contents($download->stream()));
    }

    public function testTransportIgnoringSinkWithUnreadNonSeekableBodyOfUnknownSize()
    {
        $chunks = ['abc'];
        $body = new PumpStream(function () use (&$chunks) {
            return array_shift($chunks) ?? false;
        });
        $transport = new RecordingTransport(new Response(200, [], $body));

        $download = (new InstanceClient('tok', $transport, 'http://zap.test/api'))->media(9, 'ABC', 1700000000);

        $this->assertSame('abc', stream_get_contents($download->stream()));
    }

    public function testTheSinkBodyIsClosedOnceStored()
    {
        $transport = new class implements Transport {
            public ?StreamInterface $body = null;

            public function send(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->body = new LazyOpenStream($options['sink'], 'w+');
                $this->body->write('bytes');

                return new Response(200, ['Content-Length' => '5'], $this->body);
            }
        };

        $download = (new InstanceClient('tok', $transport, 'http://zap.test/api'))->media(9, 'ABC', 1700000000);

        $this->assertSame('bytes', stream_get_contents($download->stream()));
        $this->assertFalse($transport->body->isReadable());
    }

    public function testDetachedBodyIsLostNotARawStreamError()
    {
        $response = new Response(200, ['Content-Length' => '5'], 'bytes');
        $response->getBody()->close();
        $transport = new RecordingTransport($response);

        try {
            (new InstanceClient('tok', $transport, 'http://zap.test/api'))->media(9, 'ABC', 1700000000);
            $this->fail('expected ZapmizerConnectException');
        } catch (ZapmizerUnavailableException $exception) {
            $this->fail('a detached body is not a cut body');
        } catch (ZapmizerConnectException $exception) {
            $this->assertStringContainsString('lost', $exception->getMessage());
            $this->assertFileDoesNotExist($transport->calls[0]['options']['sink']);
        }
    }

    public function testC27BytesLostByTheTransportAreUnexpected()
    {
        $body = new PumpStream(fn () => false);
        $transport = new RecordingTransport(new Response(200, ['Content-Length' => '5'], $body));

        try {
            (new InstanceClient('tok', $transport, 'http://zap.test/api'))->media(9, 'ABC', 1700000000);
            $this->fail('expected ZapmizerConnectException');
        } catch (ZapmizerUnavailableException $exception) {
            $this->fail('lost bytes are not a cut body');
        } catch (ZapmizerConnectException $exception) {
            $this->assertFileDoesNotExist($transport->calls[0]['options']['sink']);
        }
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

        $this->assertFileDoesNotExist($this->sinkOf(0));
        $this->assertFileDoesNotExist($this->sinkOf(1));
        $this->assertFileExists($this->sinkOf(2));
        $this->assertSame('bytes', stream_get_contents($download->stream()));
    }

    public function testC32WithoutTemporaryFileOnlyTheAttachedAnswerFails()
    {
        $make = function (array $queue) {
            $stack = HandlerStack::create(new MockHandler($queue));
            $stack->push(Middleware::history($this->history));

            return new class ('tok', new GuzzleTransport(new HttpClient(['handler' => $stack])), 'http://zap.test/api') extends InstanceClient {
                protected function temporaryMediaPath(): ?string
                {
                    return null;
                }
            };
        };

        $downloading = $make([new Response(202, ['Content-Type' => 'application/json'], '{"media_state":"downloading"}')])->media(9, 'ABC', 1700000000);
        $this->assertFalse($downloading->isAttached());
        $this->assertTrue($this->history[0]['options']['stream']);
        $this->assertArrayNotHasKey('sink', $this->history[0]['options']);

        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessage('could not create a temporary file for the media');

        $make([new Response(200, ['Content-Type' => 'image/png'], 'bytes')])->media(9, 'ABC', 1700000000);
    }

    public function testSilencedTempnamDoesNotRaiseUnderTheLaravelErrorHandler()
    {
        $path = @tempnam('/nonexistent-zapmizer-dir', 'zapmizer-media-');

        $this->assertIsString($path);
        @unlink($path);
    }
}
