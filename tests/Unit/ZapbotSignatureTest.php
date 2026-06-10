<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Support\ZapbotSignature;
use NotificationChannels\Zapmizer\Test\TestCase;

class ZapbotSignatureTest extends TestCase
{
    protected const SECRET = 'whsec_test';

    /**
     * Sign exactly like Zapbot does (DeliverVerifyNumberWebhookJob::signature()).
     */
    protected function sign(string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $body, static::SECRET));
    }

    public function testValidWebhookBodySignature()
    {
        $body = json_encode(['type' => 'verify_number.verified', 'number' => '5511999999999']);

        $this->assertTrue(ZapbotSignature::isValid($this->sign($body), $body, static::SECRET));
    }

    public function testTamperedBodyIsRejected()
    {
        $body = json_encode(['status' => 'verified']);

        $this->assertFalse(ZapbotSignature::isValid($this->sign($body), json_encode(['status' => 'failed']), static::SECRET));
    }

    public function testWrongSecretIsRejected()
    {
        $body = '{}';

        $this->assertFalse(ZapbotSignature::isValid($this->sign($body), $body, 'whsec_other'));
    }

    public function testExpiredTimestampIsRejected()
    {
        $body = '{}';
        $signature = $this->sign($body, time() - 4000);

        $this->assertFalse(ZapbotSignature::isValid($signature, $body, static::SECRET));
        $this->assertTrue(ZapbotSignature::isValid($signature, $body, static::SECRET, tolerance: 5000));
    }

    public function testGarbageSignatureIsRejected()
    {
        $this->assertFalse(ZapbotSignature::isValid('not-a-signature', '{}', static::SECRET));
        $this->assertFalse(ZapbotSignature::isValid('t=abc,v1=zzz', '{}', static::SECRET));
    }

    public function testValidSignedReturnQuery()
    {
        // Zapbot signs the ksorted params: status before verify_session.
        $params = ['status' => 'verified', 'verify_session' => '42'];
        ksort($params);
        $sig = $this->sign(http_build_query($params));

        // Arrives in any order, with the sig param appended.
        $query = ['verify_session' => '42', 'status' => 'verified', 'sig' => $sig];

        $this->assertTrue(ZapbotSignature::isValidQuery($query, static::SECRET));
    }

    public function testTamperedReturnQueryIsRejected()
    {
        $params = ['status' => 'failed', 'verify_session' => '42'];
        ksort($params);
        $sig = $this->sign(http_build_query($params));

        $query = ['verify_session' => '42', 'status' => 'verified', 'sig' => $sig];

        $this->assertFalse(ZapbotSignature::isValidQuery($query, static::SECRET));
    }

    public function testReturnQueryWithoutSigIsRejected()
    {
        $this->assertFalse(ZapbotSignature::isValidQuery(['status' => 'verified'], static::SECRET));
    }
}
