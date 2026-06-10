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
use NotificationChannels\Zapmizer\VerificationSession;

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

    protected function mockClient(string $method, Verification|VerificationSession $result): void
    {
        $client = Mockery::mock(VerificationClient::class);
        $client->shouldReceive($method)->andReturn($result);

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

    public function testStartVerificationRecordsAwaitingStateAndReturnsHostedPageUrl()
    {
        $this->mockClient('createSession', new VerificationSession(
            id: 'vps_abc123',
            url: 'http://localhost/verify/vps_abc123',
        ));

        $user = $this->makeUser();

        $url = $user->startWhatsappVerification();

        $this->assertEquals('http://localhost/verify/vps_abc123', $url);

        $verification = $user->whatsappVerification()->first();
        $this->assertEquals(WhatsappVerified::STATUS_AWAITING, $verification->status);
        $this->assertTrue($verification->isAwaiting());
        $this->assertEquals('vps_abc123', $verification->verification_id);
        $this->assertEquals('http://localhost/verify/vps_abc123', $verification->url);
        $this->assertNull($verification->verified_at);
        $this->assertFalse($user->hasVerifiedWhatsapp());
    }

    public function testStartVerificationPassesReturnUrlAndClientReference()
    {
        config()->set('zapmizer.return_url', 'http://app.test/verified');

        $client = Mockery::mock(VerificationClient::class);
        $client->shouldReceive('createSession')
            ->withArgs(function (?string $returnUrl, ?string $clientReference) {
                return $returnUrl === 'http://app.test/verified' && $clientReference !== null;
            })
            ->andReturn(new VerificationSession(id: 'vps_1', url: 'http://localhost/verify/vps_1'));

        $this->instance(VerificationClient::class, $client);

        $this->assertEquals('http://localhost/verify/vps_1', $this->makeUser()->startWhatsappVerification());
    }

    public function testStartVerificationWorksWithoutANumberSet()
    {
        $this->mockClient('createSession', new VerificationSession(id: 'vps_2', url: 'http://localhost/verify/vps_2'));

        $user = $this->makeUser(null);

        $this->assertEquals('http://localhost/verify/vps_2', $user->startWhatsappVerification());
        $this->assertNull($user->whatsappVerification()->first()->number);
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
        $this->mockClient('createSession', new VerificationSession(
            id: 'vps_new',
            url: 'http://localhost/verify/vps_new',
        ));

        $user = $this->makeUser();
        $user->markWhatsappAsVerified();

        $user->startWhatsappVerification();

        $this->assertFalse($user->hasVerifiedWhatsapp());
        $this->assertEquals(1, WhatsappVerified::count());
        $this->assertEquals('http://localhost/verify/vps_new', $user->whatsappVerification()->first()->url);
    }

    public function testUserRelationPointsBackToUser()
    {
        config()->set('auth.providers.users.model', User::class);

        $user = $this->makeUser();
        $user->markWhatsappAsVerified();

        $this->assertTrue(WhatsappVerified::first()->user->is($user));
    }
}
