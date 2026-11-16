# Enviar notificações

Em cada `Notification` que deve sair pelo WhatsApp, acrescente `'zapmizer'` ao array do `via()` e escreva um método `toZapmizer()`:

```php
/**
 * Get the notification's delivery channels.
 *
 * @return array<int, string>
 */
public function via(object $notifiable): array
{
    return ['mail', 'zapmizer'];
}

/**
 * Get the WhatsApp representation of the notification.
 */
public function toZapmizer(object $notifiable)
{
    $message = 'This is a message!' . PHP_EOL;

    // O WID segue o padrão do WhatsApp, por exemplo: 558181643260; 558181643260@c.us; 128172192@g.us (grupos)

    return ZapmizerMessage::create(from: config('zapmizer.from_number'), to: $notifiable->wid)->type('chat')->text($message)->send();
}
```

O WID de destino segue o padrão do WhatsApp: o número com código do país e DDD (`558181643260`), o mesmo com o sufixo `@c.us`, ou o id de um grupo (`128172192@g.us`).

## Model com número próprio

Um model que usa o trait `Connectable` (um `Team`, um `User`) manda pelo número dele, sem passar por `config('zapmizer.from_number')`:

```php
$team->zapmizerMessage('5511999999999')->text('Hello')->send();
```

O pareamento do número, o webhook assinado e as mensagens recebidas estão em [connect.md](connect.md) (em inglês).

## O que o envio devolve

`send()` (e `Zapmizer::sendMessage()`/`sendMessageWithFile()`) devolve um `Messages\SentMessage`: a mensagem foi **aceita** para envio, ainda não foi entregue.

```php
use NotificationChannels\Zapmizer\Messages\SentMessage;

$sent = $team->zapmizerMessage('5511999999999')->text('Hello')->send();

$sent->id;         // id do envio (sempre string), para acompanhar
$sent->selfUrl;    // o `links.self`: guarde este para acompanhar depois
$sent->widTo;      // o destino como a Zapmizer conferiu no WhatsApp
$sent->status;     // -2 (MessageStatus::QUEUED) na resposta do envio
$sent->raw();      // o corpo JSON decodificado, como veio
```

- **Chave do contato:** guarde o `widTo`, não o `to` que você mandou. A Zapmizer pode completar o `55` ou tirar o nono dígito, e é o `widTo` que volta em `from` no webhook `message`.
- **Número oficial com fila:** `id` e `selfUrl` vêm `null` quando o envio entra na fila. Não há como acompanhar esse envio, e isso não é erro: `$sent->isTrackable()` é `false`.
- **`api-version`:** configure `ZAPMIZER_API_VERSION=2025-06-27`. Sem ele (o padrão da config), o número por QR responde o formato antigo. O `SentMessage` ainda traz `id` e `widTo`, mas o `links.self` aponta para `/messages/{id}`, que não é link de acompanhamento: `isTrackable()` é `false`, e `track($sent)` devolve `null`.

Numa notificação, o `toZapmizer()` que chama `->send()` devolve o `SentMessage`. O Laravel o repassa ao evento `NotificationSent`, em `$event->response['message']`. Um listener pode então ligar a notificação ao envio:

```php
use Illuminate\Notifications\Events\NotificationSent;
use NotificationChannels\Zapmizer\Messages\SentMessage;

Event::listen(function (NotificationSent $event) {
    if ($event->channel === 'zapmizer' && ($event->response['message'] ?? null) instanceof SentMessage) {
        $sent = $event->response['message'];
        // guarde $sent->selfUrl junto da notificação
    }
});
```

## Acompanhar o envio

`Zapmizer::track()` segue o `links.self`, e aceita o `SentMessage` ou o link guardado. O link guardado pode ser `null` (envio oficial que entrou na fila), e `track(null)` devolve `null`. O link diz se o número é por QR (`sent-messages`) ou oficial (`meta-messages`). A chamada sai pela `base_uri` da config, com o token do cliente, e nunca pelo host escrito no link. Use o cliente da mesma conexão que enviou: um token vale para um número só.

```php
use NotificationChannels\Zapmizer\Messages\MessageStatus;

$status = $team->zapmizer()->track($sent->selfUrl);   // ou ->track($sent)

if ($status === null) {
    // nada a acompanhar: envio oficial que entrou na fila, ou formato antigo
} elseif ($status->isFailed()) {
    // -4 ou -1
} elseif ($status->status === MessageStatus::READ) {
    // lida
} elseif ($status->isDelivered()) {
    // 2 (entregue) ou 4 (reproduzida)
}
```

- Número por QR: `track()` devolve `Messages\QrMessageStatus`, com `wappMessageId` (o mesmo id do webhook `message_status`, que aparece quando o número envia), `statusLabel`, `error`, `errorMessage` e `timestamp`.
- Número oficial: devolve `Messages\MetaMessageStatus`, com `wamid`, `botInstanceId`, `errorReason` (o motivo da Meta) e `createdAt`.
- Os dois têm `id`, `status`, `statusName`, `widFrom`, `widTo`, `selfUrl` e `raw()`.
- Também dá para chamar direto: `sentMessage(string $id)` e `metaMessage(int|string $id)`.

**Compare sempre pelo número.** O `statusName` muda de texto entre a resposta do envio e o acompanhamento. As constantes de `Messages\MessageStatus` seguem a escala do contrato, a mesma do `ack` do webhook:

| Constante | Valor | Significado |
| --- | --- | --- |
| `FAILED_BEFORE_SENDING` | `-4` | Falhou antes de sair (só QR). Final. |
| `WAITING` | `-3` | Aguardando para ser enviada |
| `QUEUED` | `-2` | Na fila da Zapmizer |
| `FAILED` | `-1` | O WhatsApp recusou. Final. |
| `SENDING` | `0` | Enviando |
| `SENT` | `1` | Chegou ao servidor do WhatsApp |
| `DELIVERED` | `2` | Entregue no aparelho |
| `READ` | `3` | Lida. Final, mas nem todo contato manda confirmação de leitura. |
| `PLAYED` | `4` | Áudio ou vídeo reproduzido. Final. |

`MessageStatus::isFailure()`, `isFinal()` e `reached($status, MessageStatus::DELIVERED)` funcionam com qualquer número, inclusive o `ack` do webhook. Um valor novo da API chega como `int`, sem quebrar nada.

No webhook, o `MessageStatusUpdated` traz um `Webhook\StatusUpdate` com o `ack` nessa mesma escala e os mesmos atalhos do `SentMessage` (`isFailed()`, `isDelivered()`, `isRead()`, `isFinal()`). Ali só chegam de `-1` a `4`, e o `0` vem com o nome `pending`. Veja [webhook.md](webhook.md#message_status).

Erros: lançam as mesmas exceções do envio (veja [erros.md](erros.md)).
- **401**: token inválido ou revogado.
- **403** `missing_ability`: o token não tem `messages:read`; use o token do connect.
- **404**: id desconhecido, inclusive um id de outra conexão (o token de outro número recebe 404, não 403).
- **429** e **5xx**.

Um link passado como string que não é de acompanhamento (o `/messages/{id}` do formato antigo, por exemplo), ou um id vazio, `.` ou `..`, lança `InvalidArgumentException` antes de chamar a API.

## Template (número oficial)

No número oficial, texto livre só vale até 24 h depois da última mensagem do contato. Fora da janela, o envio responde `409 window_closed`, e o caminho é um template aprovado na Meta, por `Zapmizer::sendTemplate()` (`POST /template-messages`):

```php
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Support\PhoneNumber;

try {
    $sent = $team->zapmizerMessage('5511999999999')->text('Seu pedido saiu para entrega.')->send();
} catch (ZapmizerApiException $e) {
    if ($e->error() !== ErrorCode::WINDOW_CLOSED) {
        throw $e;
    }

    $closedAt = $e->payload()['window_expires_at'] ?? null;   // quando a janela fechou

    $template = $team->zapmizer()->sendTemplate(
        from: PhoneNumber::digits($team->zapmizerConnection->phone_number),
        to: '5511999999999',
        template: 'pedido_saiu',     // o nome aprovado na Meta
        body: ['Ana', '123'],        // as variáveis do corpo, na ordem
    );

    $template->wamid;        // id do WhatsApp (null se entrou na fila)
    $template->previewText;  // o texto como o contato recebeu
}
```

- `language` só é obrigatório quando o mesmo nome existe em mais de um idioma (`language: 'pt_BR'`). `header` é a variável do cabeçalho, quando o template tem uma.
- A resposta é um `Messages\SentTemplate`, com `wamid`, `widFrom`, `widTo`, `previewText` e `raw()`. Ela não traz `id` nem `links.self`, então não há `track()` para template.
- Os erros chegam como `ZapmizerApiException`:
  - **422**, só com `message` em `reason()`: número sem template (número por QR), template inexistente, em mais de um idioma, não aprovado, variáveis erradas ou recusa da Meta. Não repita igual.
  - **409** `bot_offline`.
  - **401**, **403**, **404** e **429** valem como no envio.
- O `window_expires_at` vem no `409 window_closed` do `POST /messages`, não na resposta do template.
