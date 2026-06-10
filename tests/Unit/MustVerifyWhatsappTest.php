<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\Verification;
use NotificationChannels\Zapmizer\VerificationClient;

class MustVerifyWhatsappTest extends TestCase
{
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

    protected function makeUser(?string $number = '+5511999999999'): User
    {
        return User::create(['name' => 'Test', 'whatsapp_number' => $number]);
    }

    protected function mockClientReturning(Verification $verification): void
    {
        $client = Mockery::mock(VerificationClient::class);
        $client->shouldReceive('create')->andReturn($verification);

        $this->instance(VerificationClient::class, $client);
    }

    public function testMigrationCreatesTableWithoutTouchingUsers()
    {
        $this->assertTrue(Schema::hasTable('whatsapp_verifieds'));
        $this->assertEquals(
            ['id', 'name', 'whatsapp_number', 'created_at', 'updated_at'],
            Schema::getColumnListing('users')
        );
    }

    public function testStartVerificationRecordsAwaitingStateAndReturnsHostedPageLink()
    {
        $this->mockClientReturning(new Verification('ver_123', 'pending', 'https://app.zapmizer.com/verify/ver_123'));

        $user = $this->makeUser();

        $url = $user->startWhatsappVerification();

        $this->assertEquals('https://app.zapmizer.com/verify/ver_123', $url);

        $verification = $user->whatsappVerification()->first();
        $this->assertEquals(WhatsappVerified::STATUS_AWAITING, $verification->status);
        $this->assertTrue($verification->isAwaiting());
        $this->assertEquals('ver_123', $verification->verification_id);
        $this->assertEquals('+5511999999999', $verification->number);
        $this->assertNull($verification->verified_at);
        $this->assertFalse($user->hasVerifiedWhatsapp());
    }

    public function testMarkWhatsappAsVerified()
    {
        $user = $this->makeUser();

        $this->assertFalse($user->hasVerifiedWhatsapp());
        $this->assertTrue($user->markWhatsappAsVerified());
        $this->assertTrue($user->hasVerifiedWhatsapp());

        $verification = $user->whatsappVerification()->first();
        $this->assertTrue($verification->isVerified());
        $this->assertNotNull($verification->verified_at);
    }

    public function testRestartingVerificationResetsVerifiedState()
    {
        $this->mockClientReturning(new Verification('ver_456', 'pending', 'https://app.zapmizer.com/verify/ver_456'));

        $user = $this->makeUser();
        $user->markWhatsappAsVerified();

        $user->startWhatsappVerification();

        $this->assertFalse($user->hasVerifiedWhatsapp());
        $this->assertEquals(1, WhatsappVerified::count());
        $this->assertEquals('ver_456', $user->whatsappVerification()->first()->verification_id);
    }

    public function testStartVerificationWithoutNumberThrows()
    {
        $user = $this->makeUser(null);

        $this->expectException(ZapmizerVerificationException::class);

        $user->startWhatsappVerification();
    }

    public function testUserRelationPointsBackToUser()
    {
        config()->set('auth.providers.users.model', User::class);

        $user = $this->makeUser();
        $user->markWhatsappAsVerified();

        $this->assertTrue(WhatsappVerified::first()->user->is($user));
    }
}
