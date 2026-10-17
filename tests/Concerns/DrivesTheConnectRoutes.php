<?php

namespace NotificationChannels\Zapmizer\Test\Concerns;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Encryption\Encrypter;
use Illuminate\Testing\TestResponse;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Test\Fixtures\Team;
use NotificationChannels\Zapmizer\Test\Fixtures\User;

/**
 * Drives the connect routes the way a browser would. The class using it
 * declares `$history` and `$reported` and uses FakesZapmizerHttp.
 */
trait DrivesTheConnectRoutes
{
    /**
     * One queue for the whole test: Laravel's Http calls every registered
     * fake callback, so two fakes would consume two queues.
     */
    protected function fakeHttp(Response|\Throwable ...$answers): void
    {
        $this->fakeZapmizer(new MockHandler($answers));
    }

    protected function actingAsUser(): User
    {
        $user = User::create(['name' => 'Test']);
        $this->actingAs($user);

        return $user;
    }

    protected function team(): Team
    {
        return Team::firstOrCreate(['name' => 'Acme']);
    }

    /**
     * What POST /api/connect/token answers once the hosted page paired the
     * number: token, team, number, instance and the webhook registered for
     * the session's webhook_url — secret only when it was created now.
     */
    protected function tokenPayload(array $overrides = []): string
    {
        return json_encode(array_merge([
            'token' => '1|sanctum', 'user_id' => 3, 'team_id' => 7, 'team_name' => 'Acme',
            'phone_number' => '5581911110000', 'bot_instance_id' => 9,
            'webhook_id' => 42, 'webhook_secret' => 'whsec_new',
        ], $overrides));
    }

    protected function sessionCreated(): Response
    {
        return new Response(201, ['Content-Type' => 'application/json'], '{"url":"http://zap.test/connect/1?s=abc","expires_at":"2026-09-07T01:00:00Z"}');
    }

    protected function tokenAnswer(array $overrides = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], $this->tokenPayload($overrides));
    }

    protected function startConnect(): TestResponse
    {
        return $this->postJson(route('zapmizer.connect.start'));
    }

    /**
     * A whole connect: start, then the popup coming back with the code and
     * the state of the session. Takes a session answer and an exchange
     * answer from the queue.
     */
    protected function connect(?string $code = 'c'): TestResponse
    {
        $this->startConnect();

        return $this->get(route('zapmizer.connect.callback', [
            'code' => $code,
            'state' => session(ConnectController::SESSION_KEY)['state'],
        ]));
    }

    protected function sentBody(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    protected function recordReports(): void
    {
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $exception) {
            $this->reported[] = $exception;
        });
    }

    protected function assertNoWebhookCalls(): void
    {
        foreach ($this->history as $entry) {
            $this->assertStringNotContainsString('/webhooks', (string) $entry['request']->getUri());
        }
    }

    /**
     * The APP_KEY changed after the stored values were encrypted.
     */
    protected function swapAppKey(): void
    {
        ZapmizerConnection::encryptUsing(new Encrypter(random_bytes(32), 'AES-256-CBC'));
        $this->beforeApplicationDestroyed(fn () => ZapmizerConnection::encryptUsing(null));
    }
}
