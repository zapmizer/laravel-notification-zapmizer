<?php

namespace NotificationChannels\Zapmizer\Testing;

use NotificationChannels\Zapmizer\Messages\SentMessage;
use NotificationChannels\Zapmizer\Messages\SentTemplate;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;

/**
 * Class FakeSend.
 *
 * One send `Zapmizer::fake()` caught, what the assertion callbacks get.
 *
 * - `kind`: `message` (`sendMessage()`, text), `file` (`sendMessageWithFile()`,
 *   `image()`/`document()`) or `template` (`sendTemplate()`).
 * - `to`/`from`: as the app passed them (not normalized; the answer's
 *   `widTo`/`widFrom` are).
 * - `text`: the text (`metadata.text`), the caption of a file, or null
 *   (a template: see `body`).
 * - `type`: the message type (`chat`, `image`, `document`...), `template`
 *   for a template.
 * - `token`, `baseUri`: the client's; `connection`: the `ZapmizerConnection`
 *   the client was made for (`$model->zapmizer()`, `zapmizerMessage()`,
 *   `$connection->message()`), or null.
 * - `params`: the parameters (or the template payload) as they would have
 *   gone; `response`: what the send answered.
 */
final class FakeSend
{
    public const MESSAGE = 'message';

    public const FILE = 'file';

    public const TEMPLATE = 'template';

    /**
     * @param array<string, mixed> $params
     * @param array<int, string> $body
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $to,
        public readonly string $from,
        public readonly ?string $text,
        public readonly ?string $type,
        public readonly array $params,
        public readonly ?string $token,
        public readonly string $baseUri,
        public readonly ?ZapmizerConnection $connection,
        public readonly SentMessage|SentTemplate $response,
        public readonly ?string $file = null,
        public readonly ?string $template = null,
        public readonly ?string $language = null,
        public readonly array $body = [],
        public readonly ?string $header = null,
    ) {
    }

    public function hasFile(): bool
    {
        return $this->file !== null;
    }

    /**
     * The file's name, as the upload would carry it.
     */
    public function filename(): ?string
    {
        return $this->file === null ? null : basename($this->file);
    }
}
