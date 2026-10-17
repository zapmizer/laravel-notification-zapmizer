<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Messages\SentTemplate;
use NotificationChannels\Zapmizer\Support\PhoneNumber;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\TestCase;

/**
 * The `409 window_closed` → template flow of docs/envio.md, as written there.
 */
class WindowClosedFlowTest extends TestCase
{
    use AssertsContract;
    use CreatesConnectionTables;

    public const WINDOW_CLOSED = '{"error":"window_closed","message":"A janela de 24h dessa conversa fechou. Só template aprovado é entregue agora.","window_expires_at":"2026-10-16T10:00:00+00:00"}';

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.base_uri', 'http://localhost/api/');
        $app['config']->set('zapmizer.api_version', '2025-06-27');
    }

    public function testAClosedWindowFallsBackToTheTemplate()
    {
        $this->assertMatchesContract('POST', '/messages', 409, self::WINDOW_CLOSED);
        $this->assertMatchesContract('POST', '/template-messages', 200, ZapmizerTemplateTest::SENT);
        Http::fake([
            'localhost/api/messages' => Http::response(self::WINDOW_CLOSED, 409, ['Content-Type' => 'application/json']),
            'localhost/api/template-messages' => Http::response(ZapmizerTemplateTest::SENT, 200, ['Content-Type' => 'application/json']),
        ]);
        $team = $this->connectedTeam(number: '+55 81 91111-0000');
        $closedAt = $template = null;

        try {
            $team->zapmizerMessage('5511999999999')->text('Seu pedido saiu para entrega.')->send();
            $this->fail('Expected the closed window.');
        } catch (ZapmizerApiException $e) {
            if ($e->error() !== ErrorCode::WINDOW_CLOSED) {
                throw $e;
            }

            $closedAt = $e->payload()['window_expires_at'] ?? null;

            $template = $team->zapmizer()->sendTemplate(
                from: PhoneNumber::digits($team->zapmizerConnection->phone_number),
                to: '5511999999999',
                template: 'pedido_saiu',
                body: ['Ana', '123'],
            );
        }

        $this->assertSame('2026-10-16T10:00:00+00:00', $closedAt);
        $this->assertInstanceOf(SentTemplate::class, $template);
        $this->assertSame('wamid.HBgMNTUxMTk5OTk5OTk5ORUCABEYEjA', $template->wamid);
        $this->assertSame('Olá, Ana! Seu pedido 123 saiu para entrega.', $template->previewText);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/api/template-messages'
            && $request->hasHeader('Authorization', 'Bearer token-+55 81 91111-0000')
            && $request->data() === [
                'from' => '5581911110000',
                'to' => '5511999999999',
                'template' => 'pedido_saiu',
                'variables' => ['body' => ['Ana', '123']],
            ]);
    }
}
