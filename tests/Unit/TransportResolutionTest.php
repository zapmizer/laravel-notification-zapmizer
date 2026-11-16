<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Test\Fixtures\AutowiredPartnerClient;
use NotificationChannels\Zapmizer\Test\Fixtures\FixedTimeoutTransport;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\Fixtures\TimeoutCapturingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\ZapmizerServiceProvider;

class TransportResolutionTest extends TestCase
{
    public function testC1DefaultIsTheLaravelTransport()
    {
        $this->assertInstanceOf(LaravelHttpTransport::class, $this->app->make(Transport::class));
    }

    public function testOwnClassReceivesTimeoutsByName()
    {
        config()->set('zapmizer.http.transport', TimeoutCapturingTransport::class);
        config()->set('zapmizer.http.connect_timeout', '10');
        config()->set('zapmizer.http.timeout', 120);

        $transport = $this->app->make(Transport::class);

        $this->assertSame(10.0, $transport->connectTimeout);
        $this->assertSame(120.0, $transport->timeout);
    }

    public function testInvalidTimeoutsAreNotPassed()
    {
        config()->set('zapmizer.http.transport', TimeoutCapturingTransport::class);

        foreach ([null, '', '0', 0, 'abc', '-5'] as $value) {
            config()->set('zapmizer.http.connect_timeout', $value);
            config()->set('zapmizer.http.timeout', $value);

            $transport = $this->app->make(Transport::class);

            $this->assertNull($transport->connectTimeout);
            $this->assertNull($transport->timeout);
        }
    }

    public function testC4OwnDefaultSurvivesAnEmptyConfig()
    {
        config()->set('zapmizer.http.transport', FixedTimeoutTransport::class);

        $this->assertSame(30.0, $this->app->make(Transport::class)->timeout);
    }

    public function testC5ClassRegisteredByTheAppIsUsed()
    {
        $mine = new TimeoutCapturingTransport(1.0, 2.0);
        $this->app->instance(TimeoutCapturingTransport::class, $mine);
        config()->set('zapmizer.http.transport', TimeoutCapturingTransport::class);
        config()->set('zapmizer.http.timeout', 99);

        $this->assertSame($mine, $this->app->make(Transport::class));
    }

    public function testC6InvalidTransportConfigThrows()
    {
        foreach ([Transport::class, 'guzzle', '0', 'App\\Missing', false, 0, []] as $value) {
            config()->set('zapmizer.http.transport', $value);

            try {
                $this->app->make(Transport::class);
                $this->fail('expected InvalidArgumentException for ' . var_export($value, true));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('zapmizer.http.transport', $exception->getMessage());
            }
        }
    }

    public function testC7EmptyTransportConfigFallsBackToTheLaravelTransport()
    {
        foreach ([null, ''] as $value) {
            config()->set('zapmizer.http.transport', $value);

            $this->assertInstanceOf(LaravelHttpTransport::class, $this->app->make(Transport::class));
        }
    }

    public function testDirectBindingRegisteredAfterTheProviderWins()
    {
        $mine = new RecordingTransport();
        $this->app->instance(Transport::class, $mine);

        $this->assertSame($mine, $this->app->make(Transport::class));
    }

    public function testDirectBindingRegisteredBeforeTheProviderWins()
    {
        $mine = new RecordingTransport();
        $this->app->instance(Transport::class, $mine);

        (new ZapmizerServiceProvider($this->app))->register();

        $this->assertSame($mine, $this->app->make(Transport::class));
    }

    public function testC12AutowiredSubclassGetsTheConfiguredTransport()
    {
        config()->set('zapmizer.http.transport', LaravelHttpTransport::class);

        $client = $this->app->make(AutowiredPartnerClient::class, ['partnerId' => 'id', 'partnerSecret' => 'secret']);

        $this->assertInstanceOf(LaravelHttpTransport::class, $client->transport());
    }

    public function testClientsBuiltWithoutATransportUseTheContainerOne()
    {
        config()->set('zapmizer.http.transport', TimeoutCapturingTransport::class);
        config()->set('zapmizer.http.timeout', 99);

        $partner = (new AutowiredPartnerClient('id', 'secret'))->transport();
        $instance = (new class ('tok') extends InstanceClient {
            public function transport(): Transport
            {
                return $this->transport;
            }
        })->transport();

        foreach ([$partner, $instance] as $transport) {
            $this->assertInstanceOf(TimeoutCapturingTransport::class, $transport);
            $this->assertSame(99.0, $transport->timeout);
        }
    }

    public function testAnInvalidTransportConfigFailsInTheClientConstructor()
    {
        config()->set('zapmizer.http.transport', 'guzzle');

        foreach ([fn () => new PartnerClient(), fn () => new InstanceClient('tok')] as $build) {
            try {
                $build();
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('zapmizer.http.transport', $exception->getMessage());
            }
        }
    }

    public function testWithoutTheProviderTheClientsFailToResolveTheTransport()
    {
        $app = Container::getInstance();
        Container::setInstance(new Container());

        try {
            try {
                new PartnerClient();
                $this->fail('expected BindingResolutionException from PartnerClient');
            } catch (BindingResolutionException) {
                $this->addToAssertionCount(1);
            }

            try {
                new InstanceClient('tok');
                $this->fail('expected BindingResolutionException from InstanceClient');
            } catch (BindingResolutionException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            Container::setInstance($app);
        }
    }
}
