<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Psr7\Response;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;
use NotificationChannels\Zapmizer\Support\ApiError;
use NotificationChannels\Zapmizer\Test\TestCase;

class ExceptionHierarchyTest extends TestCase
{
    protected function error(int $status, string $body, array $headers = []): ApiError
    {
        return ApiError::from(new Response($status, ['Content-Type' => 'application/json'] + $headers, $body));
    }

    public function testM36OneCatchForEveryExceptionOfTheLibrary()
    {
        $exceptions = [
            ZapmizerConnectException::notConnected(),
            ZapmizerUnavailableException::dueTo(),
            ZapmizerConnectException::noConnectable(),
            ZapmizerVerificationException::apiTokenNotProvided(),
            new ZapmizerApiException($this->error(422, '{}')),
            new ZapmizerRateLimitedException($this->error(429, '{}')),
            new ZapmizerUnauthorizedException($this->error(401, '{}')),
            new PartnerCredentialsException($this->error(403, '{}')),
            new InstanceGoneException($this->error(404, '{}'), 9),
            new MediaRejectedException($this->error(422, '{}')),
            new MediaRateLimitedException($this->error(429, '{}')),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(ZapmizerException::class, $exception, get_class($exception));
        }
    }

    public function testM36ApiExceptionCatchesEveryAnswerOfTheApi()
    {
        foreach ([
            ZapmizerUnauthorizedException::withoutResponse('There is no Zapmizer token for this connection.'),
            new PartnerCredentialsException($this->error(401, '{}')),
            new ZapmizerRateLimitedException($this->error(429, '{}')),
            new MediaRejectedException($this->error(422, '{}')),
            new MediaRateLimitedException($this->error(429, '{}')),
            new InstanceGoneException($this->error(404, '{}'), 9),
        ] as $exception) {
            $this->assertInstanceOf(ZapmizerApiException::class, $exception, get_class($exception));
            $this->assertInstanceOf(ZapmizerConnectException::class, $exception, get_class($exception));
        }

        $this->assertInstanceOf(ZapmizerRateLimitedException::class, new MediaRateLimitedException($this->error(429, '{}')));
        $this->assertNotInstanceOf(ZapmizerApiException::class, ZapmizerUnavailableException::dueTo());
        $this->assertNotInstanceOf(ZapmizerApiException::class, ZapmizerConnectException::noConnectable());
        $this->assertInstanceOf(NoConnectableException::class, ZapmizerConnectException::noConnectable());
    }

    public function testUnauthorizedWithAResponse()
    {
        $exception = new ZapmizerUnauthorizedException($this->error(401, '{"message":"Unauthenticated."}'), 'Zapmizer refused the connection token.');

        $this->assertSame('Zapmizer refused the connection token.', $exception->getMessage());
        $this->assertSame(401, $exception->status());
        $this->assertSame('Unauthenticated.', $exception->reason());
    }

    public function testUnauthorizedWithoutAResponse()
    {
        $exception = ZapmizerUnauthorizedException::withoutResponse('There is no Zapmizer token for this connection.');

        $this->assertSame(401, $exception->status());
        $this->assertSame('There is no Zapmizer token for this connection.', $exception->getMessage());
        $this->assertSame('There is no Zapmizer token for this connection.', $exception->reason());
        $this->assertNull($exception->error());
    }

    public function testUnauthorizedWithoutAResponseFromTheContainer()
    {
        try {
            $this->app->make(InstanceClient::class);
            $this->fail('expected a ZapmizerUnauthorizedException');
        } catch (ZapmizerUnauthorizedException $exception) {
            $this->assertSame(401, $exception->status());
            $this->assertNull($exception->error());
            $this->assertSame('InstanceClient needs the connection token — resolve it through ZapmizerConnection::instanceClient().', $exception->getMessage());
            $this->assertSame($exception->getMessage(), $exception->reason());
        }
    }

    public function testPartnerCredentialsKeepsItsMessage()
    {
        $exception = new PartnerCredentialsException($this->error(403, '{"message":"Forbidden."}'));

        $this->assertSame('Zapmizer refused the partner credentials.', $exception->getMessage());
        $this->assertSame(403, $exception->status());
        $this->assertSame('Forbidden.', $exception->reason());
    }

    public function testInstanceGoneKeepsItsMessage()
    {
        $exception = new InstanceGoneException($this->error(404, '{"message":"Not found."}'), 9);

        $this->assertSame('Instance 9 is gone on Zapmizer.', $exception->getMessage());
        $this->assertSame(404, $exception->status());
    }

    public function testMediaRejectedKeepsItsMessageAndReason()
    {
        $exception = ZapmizerConnectException::mediaRejected($this->error(422, '{"message":"Media is not available for Meta Cloud instances.","errors":{"bot_instance_id":["Meta Cloud."]}}'));

        $this->assertInstanceOf(MediaRejectedException::class, $exception);
        $this->assertSame('Media is not available for Meta Cloud instances. Meta Cloud.', $exception->reason());
        $this->assertSame('Zapmizer rejected the media request: Media is not available for Meta Cloud instances. Meta Cloud.', $exception->getMessage());
        $this->assertSame(422, $exception->status());
    }

    public function testMediaRateLimitedKeepsItsMessageAndRetryAfter()
    {
        $limited = ZapmizerConnectException::mediaRateLimited($this->error(429, '{"message":"Too Many Attempts."}', ['Retry-After' => '37']));
        $withoutHeader = ZapmizerConnectException::mediaRateLimited($this->error(429, '{"message":"Too Many Attempts."}'));

        $this->assertInstanceOf(MediaRateLimitedException::class, $limited);
        $this->assertSame('Zapmizer rate-limited the media request (60 a minute per user and instance). Retry in 37 s.', $limited->getMessage());
        $this->assertSame(37, $limited->retryAfter());
        $this->assertSame(429, $limited->status());
        $this->assertSame('Zapmizer rate-limited the media request (60 a minute per user and instance).', $withoutHeader->getMessage());
        $this->assertNull($withoutHeader->retryAfter());
    }

    public function testNotPairedAndUnreadableFile()
    {
        $this->assertSame('There is no paired Zapmizer instance for this connection.', ZapmizerConnectException::notPaired()->getMessage());
        $this->assertSame('Could not open /tmp/missing.png to send.', ZapmizerConnectException::unreadableFile('/tmp/missing.png')->getMessage());
        $this->assertNotInstanceOf(ZapmizerApiException::class, ZapmizerConnectException::notPaired());
    }

    public function testThePackageExceptionsDoNotRender()
    {
        foreach ([
            ZapmizerConnectException::class,
            ZapmizerApiException::class,
            ZapmizerUnavailableException::class,
            PartnerCredentialsException::class,
            NoConnectableException::class,
            ZapmizerUnauthorizedException::class,
        ] as $class) {
            $this->assertFalse(method_exists($class, 'render'), $class);
        }
    }
}
