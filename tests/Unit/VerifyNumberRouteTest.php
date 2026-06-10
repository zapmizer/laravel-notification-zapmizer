<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\VerificationClient;
use NotificationChannels\Zapmizer\VerificationSession;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class VerifyNumberRouteTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->timestamps();
        });

        $migration = include __DIR__ . '/../../database/migrations/create_whatsapp_verifieds_table.php.stub';
        $migration->up();
    }

    protected function usesCustomPrefixAndMiddleware($app)
    {
        $app['config']->set('zapmizer.routes.prefix', 'custom-prefix');
        $app['config']->set('zapmizer.routes.middleware', ['web']);
    }

    protected function usesDisabledRoutes($app)
    {
        $app['config']->set('zapmizer.routes.enabled', false);
    }

    protected function mockClientReturning(VerificationSession $session): void
    {
        $client = Mockery::mock(VerificationClient::class);
        $client->shouldReceive('createSession')->andReturn($session);

        $this->instance(VerificationClient::class, $client);
    }

    public function testNamedRouteExistsWithAuthMiddlewareAndDefaultPrefix()
    {
        $route = Route::getRoutes()->getByName('zapmizer.verify_number');

        $this->assertNotNull($route);
        $this->assertEquals('zapmizer/verify-number', $route->uri());
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    public function testRouteStartsVerificationAndRedirectsToHostedPage()
    {
        $this->mockClientReturning(new VerificationSession(
            url: 'http://localhost/verify-number/1?number=5511999999999&signature=abc',
        ));

        $user = User::create(['name' => 'Test', 'whatsapp_number' => '5511999999999']);

        $response = $this->actingAs($user)->get(route('zapmizer.verify_number'));

        $response->assertRedirect('http://localhost/verify-number/1?number=5511999999999&signature=abc');

        $verification = $user->whatsappVerification()->first();
        $this->assertEquals(WhatsappVerified::STATUS_AWAITING, $verification->status);
        $this->assertEquals('5511999999999', $verification->number);
    }

    public function testGuestsAreRejectedByDefault()
    {
        Route::get('login', fn () => 'login page')->name('login');

        $this->get(route('zapmizer.verify_number'))->assertRedirect(route('login'));

        $this->assertEquals(0, WhatsappVerified::count());
    }

    #[DefineEnvironment('usesCustomPrefixAndMiddleware')]
    public function testRespectsConfiguredPrefixAndMiddleware()
    {
        $route = Route::getRoutes()->getByName('zapmizer.verify_number');

        $this->assertEquals('custom-prefix/verify-number', $route->uri());
        $this->assertNotContains('auth', $route->gatherMiddleware());
    }

    #[DefineEnvironment('usesDisabledRoutes')]
    public function testRoutesCanBeDisabled()
    {
        $this->assertNull(Route::getRoutes()->getByName('zapmizer.verify_number'));
    }
}
