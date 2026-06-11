<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\VerificationClient;
use NotificationChannels\Zapmizer\VerificationResult;
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

    protected function mockClient(string $method, VerificationSession|VerificationResult $result): void
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
            url: 'http://localhost/verify-number/1?number=5511999999999&signature=abc',
        ));

        $user = $this->makeUser();

        $url = $user->startWhatsappVerification();

        $this->assertEquals('http://localhost/verify-number/1?number=5511999999999&signature=abc', $url);

        $verification = $user->whatsappVerification()->first();
        $this->assertEquals(WhatsappVerified::STATUS_AWAITING, $verification->status);
        $this->assertTrue($verification->isAwaiting());
        $this->assertEquals('5511999999999', $verification->number);
        $this->assertEquals($url, $verification->url);
        $this->assertNull($verification->verified_at);
        $this->assertFalse($user->hasVerifiedWhatsapp());
    }

    public function testStartVerificationPassesNumberConfiguredFromAndReturnUrl()
    {
        config()->set('zapmizer.from_number', '5581999999999');
        config()->set('zapmizer.return_url', 'http://app.test/account');

        $client = Mockery::mock(VerificationClient::class);
        $client->shouldReceive('createSession')
            ->withArgs(function (?string $number, ?string $from, ?string $returnUrl) {
                return $number === '5511999999999'
                    && $from === '5581999999999'
                    && $returnUrl === 'http://app.test/account';
            })
            ->andReturn(new VerificationSession(url: 'http://localhost/verify-number/1?signature=abc'));

        $this->instance(VerificationClient::class, $client);

        $this->assertNotEmpty($this->makeUser()->startWhatsappVerification());
    }

    public function testStartVerificationReturnUrlArgumentOverridesConfig()
    {
        config()->set('zapmizer.return_url', 'http://app.test/account');

        $client = Mockery::mock(VerificationClient::class);
        $client->shouldReceive('createSession')
            ->withArgs(fn (?string $number, ?string $from, ?string $returnUrl) => $returnUrl === 'http://app.test/checkout')
            ->andReturn(new VerificationSession(url: 'http://localhost/verify-number/1?signature=abc'));

        $this->instance(VerificationClient::class, $client);

        $this->assertNotEmpty($this->makeUser()->startWhatsappVerification('http://app.test/checkout'));
    }

    public function testStartVerificationWorksWithoutANumberSet()
    {
        $this->mockClient('createSession', new VerificationSession(url: 'http://localhost/verify-number/1?signature=abc'));

        $user = $this->makeUser(null);

        $this->assertNotEmpty($user->startWhatsappVerification());
        $this->assertNull($user->whatsappVerification()->first()->number);
    }

    public function testConfirmWithCorrectCodeMarksAsVerifiedWithCanonicalNumber()
    {
        $user = $this->makeUser('11999999999'); // stored without country code
        $user->whatsappVerification()->create([
            'number' => '11999999999',
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        $this->mockClient('confirm', new VerificationResult(
            status: 'verified',
            number: '5511999999999', // canonical, as resolved by Zapmizer
            from: '5581999999999',
        ));

        $this->assertTrue($user->confirmWhatsappVerification('123456'));
        $this->assertTrue($user->hasVerifiedWhatsapp());

        $record = $user->whatsappVerification()->first();
        $this->assertEquals('5511999999999', $record->number);
        $this->assertNotNull($record->verified_at);
    }

    public function testConfirmWithoutPriorStartUsesTheModelNumber()
    {
        $user = $this->makeUser();

        $this->mockClient('confirm', new VerificationResult(status: 'verified', number: '5511999999999'));

        $this->assertTrue($user->confirmWhatsappVerification('123456'));
        $this->assertTrue($user->hasVerifiedWhatsapp());
    }

    public function testConfirmWithWrongCodeReturnsFalseAndKeepsAwaiting()
    {
        $user = $this->makeUser();
        $user->whatsappVerification()->create([
            'number' => '5511999999999',
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        $this->mockClient('confirm', new VerificationResult(status: 'invalid', attemptsLeft: 3));

        $this->assertFalse($user->confirmWhatsappVerification('000000'));
        $this->assertFalse($user->hasVerifiedWhatsapp());
        $this->assertEquals(WhatsappVerified::STATUS_AWAITING, $user->whatsappVerification()->first()->status);
    }

    public function testConfirmExhaustedMovesStateToFailed()
    {
        $user = $this->makeUser();
        $user->whatsappVerification()->create([
            'number' => '5511999999999',
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        $this->mockClient('confirm', new VerificationResult(status: 'failed', attemptsLeft: 0));

        $this->assertFalse($user->confirmWhatsappVerification('000000'));
        $this->assertEquals(WhatsappVerified::STATUS_FAILED, $user->whatsappVerification()->first()->status);
    }

    public function testConfirmNotFoundReturnsFalseWithoutChangingState()
    {
        $user = $this->makeUser();
        $user->whatsappVerification()->create([
            'number' => '5511999999999',
            'status' => WhatsappVerified::STATUS_AWAITING,
        ]);

        $this->mockClient('confirm', new VerificationResult(status: 'not_found'));

        $this->assertFalse($user->confirmWhatsappVerification('123456'));
        $this->assertEquals(WhatsappVerified::STATUS_AWAITING, $user->whatsappVerification()->first()->status);
    }

    public function testConfirmWithoutAnyNumberThrows()
    {
        $user = $this->makeUser(null);

        $this->expectException(ZapmizerVerificationException::class);

        $user->confirmWhatsappVerification('123456');
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
        $this->mockClient('createSession', new VerificationSession(url: 'http://localhost/verify-number/1?signature=new'));

        $user = $this->makeUser();
        $user->markWhatsappAsVerified();

        $user->startWhatsappVerification();

        $this->assertFalse($user->hasVerifiedWhatsapp());
        $this->assertEquals(1, WhatsappVerified::count());
        $this->assertEquals('http://localhost/verify-number/1?signature=new', $user->whatsappVerification()->first()->url);
    }

    public function testUserRelationPointsBackToUser()
    {
        config()->set('auth.providers.users.model', User::class);

        $user = $this->makeUser();
        $user->markWhatsappAsVerified();

        $this->assertTrue(WhatsappVerified::first()->user->is($user));
    }
}
