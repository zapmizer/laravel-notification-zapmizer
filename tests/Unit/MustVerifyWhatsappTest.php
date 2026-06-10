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

    protected function makeUser(?string $number = '5511999999999'): User
    {
        return User::create(['name' => 'Test', 'whatsapp_number' => $number]);
    }

    protected function mockClient(string $method, Verification $verification): void
    {
        $client = Mockery::mock(VerificationClient::class);
        $client->shouldReceive($method)->andReturn($verification);

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

    public function testStartVerificationRecordsAwaitingStateAndReturnsWaLink()
    {
        $this->mockClient('create', new Verification(
            number: '5511999999999',
            status: 'pending',
            waLink: 'https://wa.me/5581999999999?text=ABC123',
        ));

        $user = $this->makeUser();

        $waLink = $user->startWhatsappVerification();

        $this->assertEquals('https://wa.me/5581999999999?text=ABC123', $waLink);

        $verification = $user->whatsappVerification()->first();
        $this->assertEquals(WhatsappVerified::STATUS_AWAITING, $verification->status);
        $this->assertTrue($verification->isAwaiting());
        $this->assertEquals('5511999999999', $verification->number);
        $this->assertEquals('https://wa.me/5581999999999?text=ABC123', $verification->url);
        $this->assertNull($verification->verified_at);
        $this->assertFalse($user->hasVerifiedWhatsapp());
    }

    public function testStartVerificationWhileResolvingReturnsNullLink()
    {
        $this->mockClient('create', new Verification(number: '5511999999999', status: 'resolving'));

        $user = $this->makeUser();

        $this->assertNull($user->startWhatsappVerification());
        $this->assertEquals(WhatsappVerified::STATUS_AWAITING, $user->whatsappVerification()->first()->status);
    }

    public function testConfirmVerificationWithCorrectCodeMarksAsVerified()
    {
        $user = $this->makeUser();
        $user->whatsappVerification()->create([
            'number' => '5511999999999',
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        $this->mockClient('confirm', new Verification(
            number: '5511999999999',
            status: 'verified',
            verifiedAt: '2026-06-10T00:00:00.000000Z',
        ));

        $this->assertTrue($user->confirmWhatsappVerification('123456'));
        $this->assertTrue($user->hasVerifiedWhatsapp());
        $this->assertNotNull($user->whatsappVerification()->first()->verified_at);
    }

    public function testConfirmWithoutStartedVerificationThrows()
    {
        $user = $this->makeUser();

        $this->expectException(ZapmizerVerificationException::class);

        $user->confirmWhatsappVerification('123456');
    }

    public function testSyncUpdatesStateFromZapbot()
    {
        $user = $this->makeUser();
        $user->whatsappVerification()->create([
            'number' => '5511999999999',
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        $this->mockClient('get', new Verification(
            number: '5511999999999',
            status: 'verified',
            verifiedAt: '2026-06-10T00:00:00.000000Z',
        ));

        $this->assertEquals(WhatsappVerified::STATUS_VERIFIED, $user->syncWhatsappVerificationStatus());
        $this->assertTrue($user->hasVerifiedWhatsapp());
    }

    public function testSyncMapsTerminalFailuresToFailed()
    {
        $user = $this->makeUser();
        $user->whatsappVerification()->create([
            'number' => '5511999999999',
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        $this->mockClient('get', new Verification(number: '5511999999999', status: 'expired'));

        $this->assertEquals(WhatsappVerified::STATUS_FAILED, $user->syncWhatsappVerificationStatus());
        $this->assertFalse($user->hasVerifiedWhatsapp());
    }

    public function testSyncWithoutStartedVerificationReturnsNull()
    {
        $user = $this->makeUser();

        $this->assertNull($user->syncWhatsappVerificationStatus());
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
        $this->mockClient('create', new Verification(
            number: '5511999999999',
            status: 'pending',
            waLink: 'https://wa.me/5581999999999?text=DEF456',
        ));

        $user = $this->makeUser();
        $user->markWhatsappAsVerified();

        $user->startWhatsappVerification();

        $this->assertFalse($user->hasVerifiedWhatsapp());
        $this->assertEquals(1, WhatsappVerified::count());
        $this->assertEquals('https://wa.me/5581999999999?text=DEF456', $user->whatsappVerification()->first()->url);
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
