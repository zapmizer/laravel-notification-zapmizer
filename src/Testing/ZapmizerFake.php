<?php

namespace NotificationChannels\Zapmizer\Testing;

use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use NotificationChannels\Zapmizer\Connect\ZapmizerApi;
use NotificationChannels\Zapmizer\Messages\MessageStatus;
use NotificationChannels\Zapmizer\Messages\MetaMessageStatus;
use NotificationChannels\Zapmizer\Messages\QrMessageStatus;
use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\Messages\SentTemplate;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Support\PhoneNumber;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * Class ZapmizerFake.
 *
 * What `Facades\Zapmizer::fake()` puts in the container. Every send of the
 * package goes through `NotificationChannels\Zapmizer\Zapmizer` (the
 * facade, `ZapmizerMessage::send()`, the `zapmizer` channel, the client of
 * a connection, a client made by hand), and that class hands the send to
 * this fake while it is bound: nothing leaves, each send answers a
 * `SentMessage::fake()` (or a `SentTemplate`) with its own id and the
 * `wid_to`/`wid_from` of the numbers given, and is kept for the
 * assertions. Tracking (`track()`, `sentMessage()`, `metaMessage()`)
 * answers a queued status without asking anything either.
 */
class ZapmizerFake
{
    /** @var array<int, FakeSend> */
    protected array $sends = [];

    /** @var Closure|Throwable|null */
    protected $failure = null;

    /**
     * Make the sends fail: every one with the exception, or the ones for
     * which the closure (given the `FakeSend`) returns a Throwable (null
     * lets the send through). A failed send is not recorded. `null` stops
     * failing.
     *
     * @return $this
     */
    public function failWith(Throwable|Closure|null $failure): static
    {
        $this->failure = $failure;

        return $this;
    }

    /**
     * Make every send fail as the API would answer `$status` with `$body`:
     * `ZapmizerUnauthorizedException` (401), `ZapmizerRateLimitedException`
     * (429, `Retry-After` from `$headers`) or `ZapmizerApiException`
     * (`error()`, `reason()`, `payload()` read from the body, as for real).
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     *
     * @return $this
     */
    public function failWithApiError(int $status, array $body = [], array $headers = []): static
    {
        return $this->failWith(ZapmizerApi::failure(new Response(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR),
        )));
    }

    /**
     * @param (callable(FakeSend): bool)|null $callback
     */
    public function assertSent(?callable $callback = null): void
    {
        if ($callback === null) {
            PHPUnit::assertNotEmpty($this->sends, 'No message was sent through Zapmizer.');

            return;
        }

        PHPUnit::assertTrue(
            $this->sent($callback)->isNotEmpty(),
            'No message matching the callback was sent through Zapmizer. Sent: ' . $this->describe($this->sends),
        );
    }

    /**
     * @param (callable(FakeSend): bool)|null $callback
     */
    public function assertSentTimes(int $times, ?callable $callback = null): void
    {
        $count = $this->sent($callback)->count();

        PHPUnit::assertSame($times, $count, "Expected {$times} " . ($times === 1 ? 'send' : 'sends') . " through Zapmizer, sent {$count} " . ($count === 1 ? 'time' : 'times') . '.');
    }

    /**
     * @param (callable(FakeSend): bool)|null $callback
     */
    public function assertNotSent(?callable $callback = null): void
    {
        $matching = $this->sent($callback);

        PHPUnit::assertTrue($matching->isEmpty(), 'An unexpected message was sent through Zapmizer: ' . $this->describe($matching->all()));
    }

    public function assertNothingSent(): void
    {
        $count = count($this->sends);

        PHPUnit::assertSame(0, $count, "Expected nothing sent through Zapmizer, {$count} " . ($count === 1 ? 'message was' : 'messages were') . ' sent: ' . $this->describe($this->sends));
    }

    /**
     * The sends, all of them or the ones the callback accepts.
     *
     * @param (callable(FakeSend): bool)|null $callback
     *
     * @return Collection<int, FakeSend>
     */
    public function sent(?callable $callback = null): Collection
    {
        $sends = new Collection($this->sends);

        return $callback === null ? $sends : $sends->filter(fn (FakeSend $send) => (bool) $callback($send))->values();
    }

    public function hasSent(): bool
    {
        return $this->sends !== [];
    }

    /**
     * `Zapmizer::sendMessage()` / `sendMessageWithFile()` caught.
     *
     * @internal called by NotificationChannels\Zapmizer\Zapmizer
     *
     * @param array<string, mixed> $params
     *
     * @throws Throwable the failure set by failWith()
     */
    public function message(array $params, ?string $file, ?string $token, string $baseUri, ?ZapmizerConnection $connection): SentMessage
    {
        $to = $this->scalar($params['to'] ?? null);
        $from = $this->scalar($params['from'] ?? null);
        $type = $this->scalar($params['type'] ?? null);
        $text = $file === null ? ($params['metadata']['text'] ?? null) : ($params['text'] ?? $params['metadata']['text'] ?? null);

        $response = SentMessage::fake(array_filter([
            'type' => $type,
            'wid_to' => $this->wid($to),
            'wid_from' => $this->wid($from),
            'metadata' => ['text' => is_scalar($text) ? (string) $text : null],
        ], fn ($value) => $value !== null));

        return $this->record(new FakeSend(
            kind: $file === null ? FakeSend::MESSAGE : FakeSend::FILE,
            to: $to,
            from: $from,
            text: is_scalar($text) ? (string) $text : null,
            type: $type === '' ? null : $type,
            params: $params,
            token: $token,
            baseUri: $baseUri,
            connection: $connection,
            response: $response,
            file: $file,
        ));
    }

    /**
     * `Zapmizer::sendTemplate()` caught.
     *
     * @internal called by NotificationChannels\Zapmizer\Zapmizer
     *
     * @param array<string, mixed> $payload
     *
     * @throws Throwable the failure set by failWith()
     */
    public function template(array $payload, ?string $token, string $baseUri, ?ZapmizerConnection $connection): SentTemplate
    {
        $to = $this->scalar($payload['to'] ?? null);
        $from = $this->scalar($payload['from'] ?? null);
        $body = $payload['variables']['body'] ?? [];

        $response = SentTemplate::fromArray([
            'wamid' => 'wamid.fake-' . bin2hex(random_bytes(8)),
            'wid_from' => $this->wid($from),
            'wid_to' => $this->wid($to),
            'preview_text' => null,
        ]);

        return $this->record(new FakeSend(
            kind: FakeSend::TEMPLATE,
            to: $to,
            from: $from,
            text: null,
            type: 'template',
            params: $payload,
            token: $token,
            baseUri: $baseUri,
            connection: $connection,
            response: $response,
            template: $this->scalar($payload['template'] ?? null),
            language: isset($payload['language']) ? $this->scalar($payload['language']) : null,
            body: is_array($body) ? array_values(array_map('strval', $body)) : [],
            header: isset($payload['variables']['header']) ? $this->scalar($payload['variables']['header']) : null,
        ));
    }

    /**
     * `Zapmizer::sentMessage()` (and `track()` of a QR send) caught: the
     * send as queued, with the numbers of the fake send of that id.
     *
     * @internal called by NotificationChannels\Zapmizer\Zapmizer
     */
    public function qrStatus(string $id): QrMessageStatus
    {
        return QrMessageStatus::fromArray($this->status($id, 'sent-messages'));
    }

    /**
     * `Zapmizer::metaMessage()` (and `track()` of an official send) caught.
     *
     * @internal called by NotificationChannels\Zapmizer\Zapmizer
     */
    public function metaStatus(string $id): MetaMessageStatus
    {
        return MetaMessageStatus::fromArray($this->status($id, 'meta-messages'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function status(string $id, string $kind): array
    {
        $send = $this->sent(fn (FakeSend $send) => $send->response instanceof SentMessage && $send->response->id === $id)->first();

        return ['data' => [
            'id' => $id,
            'message_status' => MessageStatus::QUEUED,
            'message_status_name' => 'Enviando',
            'wid_from' => $send?->response->widFrom,
            'wid_to' => $send?->response->widTo,
            'links' => ['self' => 'https://app.zapmizer.com/api/' . $kind . '/' . rawurlencode($id)],
        ]];
    }

    /**
     * @throws Throwable
     */
    protected function record(FakeSend $send): SentMessage|SentTemplate
    {
        $failure = $this->failure instanceof Closure ? ($this->failure)($send) : $this->failure;

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        $this->sends[] = $send;

        return $send->response;
    }

    /**
     * `5511999999999` → `5511999999999@c.us`; a wid stays as it is.
     */
    protected function wid(string $number): ?string
    {
        if (str_contains($number, '@')) {
            return $number;
        }

        $digits = PhoneNumber::digits($number);

        return $digits === '' ? null : $digits . '@c.us';
    }

    protected function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<int, FakeSend> $sends
     */
    protected function describe(array $sends): string
    {
        if ($sends === []) {
            return 'nothing.';
        }

        return implode('; ', array_map(fn (FakeSend $send) => "{$send->kind} to {$send->to}" . ($send->text !== null ? " (\"{$send->text}\")" : '') . ($send->template !== null ? " (template {$send->template})" : ''), $sends)) . '.';
    }
}
