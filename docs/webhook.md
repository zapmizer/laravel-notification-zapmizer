# Eventos do webhook

A rota do webhook (`POST /zapmizer/webhook`, e a rota por conexão quando ligada) só existe com o grupo webhook ligado (`ZAPMIZER_ROUTES_WEBHOOK=true`; veja [instalar.md](instalar.md#as-rotas-da-lib)). Ela valida a assinatura e transforma cada evento do catálogo da Zapmizer num evento do Laravel. A lib não guarda nada: o app escuta e decide. A assinatura, os segredos e a rota por conexão estão em [connect.md §6](connect.md#6-receiving-messages-the-signed-webhook).

## Ordem dos eventos numa entrega

1. `WebhookReceived`, com o payload cru, para toda entrega que passou pela assinatura.
2. O evento tipado do catálogo (tabela abaixo), quando o `data` dá para ler.
3. O `handle{NomeEmStudly}` do controller, se existir. Para `message_received`, `message_sent` e `message` é o da lib, que dispara `MessageReceived` ou `MessageSent` (veja "Mensagens" abaixo); um `handle*` que o app escreveu numa subclasse roda aqui. Depois de um `handle*`, sai o `WebhookHandled`.

Os eventos do catálogo não têm `handle*` na lib. Por isso continuam respondendo `Webhook Received`, não disparam `WebhookHandled` e passam pelo `missingMethod`, como antes. O evento tipado se soma ao `WebhookReceived`.

## Catálogo

| `name` | Evento | Propriedade | Campos |
|---|---|---|---|
| `message_status` | `MessageStatusUpdated` | `status` (`StatusUpdate`) | `wappMessageId`, `to`, `ack`, `status`, `occurredAt` |
| `connection` | `ConnectionStateChanged` | `state` (`ConnectionState`) | `state`, `reason`, `kind` |
| `message_reacted` | `MessageReacted` | `reaction` (`Reaction`) | `wappMessageId`, `chatWid`, `from`, `emoji`, `removed`, `occurredAt` |
| `message_edited` | `MessageEdited` | `edit` (`MessageEdit`) | `wappMessageId`, `chatWid`, `fromMe`, `body`, `previousBody`, `occurredAt` |
| `message_revoked` | `MessageRevoked` | `revocation` (`MessageRevocation`) | `wappMessageId`, `chatWid`, `fromMe`, `scope`, `occurredAt` |
| `message_transcribed` | `MessageTranscribed` | `transcription` (`Transcription`) | `wappMessageId`, `chatWid`, `text`, `durationSeconds`, `model`, `source`, `occurredAt` |
| `incoming_call` | `CallReceived` | `call` (`IncomingCall`) | `callId`, `from`, `isVideo`, `isGroup`, `fromMe`, `occurredAt` |
| `poll_vote` | `PollVoted` | `vote` (`PollVote`) | `wappMessageId`, `chatWid`, `voter`, `selectedOptions`, `occurredAt` |
| `contact_number_changed` | `ContactNumberChanged` | `change` (`ContactNumberChange`) | `oldWid`, `newWid`, `isContact`, `occurredAt` |
| `group_membership` | `GroupMembershipChanged` | `membership` (`GroupMembership`) | `groupWid`, `kind`, `by`, `occurredAt` |
| `group_admins_changed` | `GroupAdminsChanged` | `change` (`GroupAdminsChange`) | `groupWid`, `kind`, `participants`, `by`, `occurredAt` |
| `group_join_request` | `GroupJoinRequested` | `joinRequest` (`GroupJoinRequest`) | `groupWid`, `requester`, `participants`, `occurredAt` |
| `group_updated` | `GroupUpdated` | `update` (`GroupUpdate`) | `groupWid`, `change`, `body`, `by`, `occurredAt` |

Os eventos ficam em `NotificationChannels\Zapmizer\Events` e estendem `WebhookEvent`. Os DTOs ficam em `NotificationChannels\Zapmizer\Webhook`, são só leitura e trazem o `data` cru em `raw`. `occurredAt` é o `timestamp` do `data` (segundos, UTC) num `Carbon`, ou `null`.

Todo evento tipado também tem:

- `$event->envelope`: `id` (o mesmo em todas as tentativas da entrega), `name`, `createdAt` (`Carbon`, quando a Zapmizer gerou o evento), `wid` (o número que gerou o evento, `null` em alguns `connection`) e `version` (a versão do webhook, `null` em Zapmizer anterior à 1.148.17; veja "Versão do webhook" abaixo);
- `$event->connection`: a `ZapmizerConnection` cujo segredo assinou a entrega, `null` no single-tenant;
- `$event->payload`: o corpo decodificado.

O `MessageReceived` e o `MessageSent` também têm o `$event->envelope`.

O `created_at` só é lido em ISO 8601 estrito, com fuso (`2025-10-17T12:00:00.000000Z`); outro formato vira `createdAt` `null` e uma linha de log.

### Leitura tolerante

Cada evento depende de um campo que o identifica: `wapp_message_id`, `call_id`, `old_wid` e `new_wid`, `group_wid`, `state` ou `ack`. Se esse campo falta, vem vazio ou tem tipo errado, o evento tipado não sai, e o resto da entrega segue igual (`WebhookReceived`, 2xx). Nos outros campos, um tipo errado vira `null`, `false` ou `[]`. Um valor novo de enum num campo secundário (`kind`, `scope`, `source`, `change`) chega como veio, sem precisar de release da lib.

Um `name` fora do catálogo não dispara nada novo.

### Versão do webhook

Cada webhook da Zapmizer tem uma versão, fixada na criação, que vem em `version` no envelope (Zapmizer 1.148.17 ou posterior; antes, `version` vem `null`). A lib lê as duas que existem:

- **`2026-10-20`**, o contrato de mensagem: as mensagens chegam como `message_received` e `message_sent`, no mesmo formato para número por QR e oficial. É a versão do webhook que o connect e o `createWebhook()` criam (veja "The webhook version" em [connect.md](connect.md#the-webhook-version)).
- **`2024-02-27`**, descontinuada pela Zapmizer (a data de desligamento ainda vai ser anunciada): as mensagens recebidas chegam como `message`, no formato do WhatsApp Web, e a lib as traduz para o mesmo `Message`. Funciona igual, com duas diferenças: `MessageSent` nunca dispara (o `message` só traz o que o número recebeu) e só número por QR entrega `message`.

O catálogo acima não muda entre as duas.

## Mensagens

```php
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Events\MessageSent;
use NotificationChannels\Zapmizer\Messages\MessageType;

Event::listen(function (MessageReceived $event) {
    $message = $event->message; // Messages\Message

    if ($message->type === MessageType::DOCUMENT && $message->media?->mime === 'application/pdf') {
        ProcessBill::dispatch($event->connection, $message);
    }
});

Event::listen(function (MessageSent $event) {
    if ($event->message->fromMeOtherDevice) {
        // a pessoa respondeu pelo celular
    }
});
```

| `name` | Versão | Evento |
|---|---|---|
| `message_received` | `2026-10-20` | `MessageReceived` |
| `message_sent` | `2026-10-20` | `MessageSent` |
| `message` | `2024-02-27` | `MessageReceived`, traduzido |

Os dois eventos recebem `Message $message`, `?ZapmizerConnection $connection`, `array $payload` (o corpo decodificado) e `?Envelope $envelope`. O número que recebeu ou enviou a mensagem é o `$event->envelope->wid` (e a `$event->connection`, no multi-tenant): o contrato não traz esse campo na mensagem.

- **`Messages\Message`** tem os campos do `data` de `message_received`, em camelCase: `id`, `chat`, `sender`, `senderName`, `fromMe`, `fromMeOtherDevice`, `timestamp`, `type`, `text`, `quoted`, `forwarded`, `viewOnce`, `mentions`, `media`, `location`, `contacts`, `poll`, `reply`, `linkPreview` e `raw`. Os aninhados são DTOs pequenos (`MessageMedia`, `QuotedMessage`, `MessageLocation`, `MessagePoll`, `MessageReply`, `LinkPreview`); `contacts` é a lista de `{name, vcard}` como veio. Atalhos: `sentAt()`, `chatPhone()` (os dígitos de um `...@c.us`; `null` em grupo, `@lid`, transmissão e canal), `senderPhone()`, `isGroup()`, `isBroadcast()` e `hasMedia()`.
- **`type`** é uma das constantes de `Messages\MessageType` (`text`, `image`, `video`, `audio`, `voice`, `document`, `sticker`, `location`, `contact`, `poll`, `button_reply`, `list_reply`, `unknown`). É uma string: um tipo novo da Zapmizer chega sem release da lib. `unknown` traz a mensagem original em `raw`: guarde, não descarte.
- **`id`** é o id da mensagem no WhatsApp (`wamid...` no número oficial). Ele pode se repetir entre conversas: deduplique por `chat` + `id`.
- **`chat`** pode ser `...@lid` quando o WhatsApp não revelou o número; aí `chatPhone()` é `null`.
- **Avisos do WhatsApp não são mensagens.** A entrada de alguém num grupo, o aviso de criptografia, a mensagem apagada ou editada e a que ainda não chegou inteira não disparam `MessageReceived`, em nenhuma das versões: a Zapmizer não os entrega no contrato, e a lib aplica a mesma regra ao `message` da `2024-02-27`. A mensagem editada chega no `message_edited` e a apagada no `message_revoked`.
- **`MessageSent` dispara para tudo o que o número envia**: pela API (inclusive as notificações do próprio app), pelo bot e pelo celular. `fromMeOtherDevice` separa o que saiu de outro aparelho, como o celular (em grupo é sempre `false`). A lib não separa o que saiu da API do que saiu do bot: o `id` do `SentMessage` é o id interno da Zapmizer, não o do WhatsApp, e o `wapp_message_id` só aparece depois, no `message_status` ou no `track()`.
- **Número oficial.** Os dois eventos chegam no mesmo formato. A mídia não se baixa pela lib (`media()` responde 422, `MediaRejectedException`), e `media` traz só `mime` e `filename`.

### O formato `2024-02-27`

O `message` traz em `data[0]` a mensagem do WhatsApp Web, o mesmo objeto que o `syncMessages()` de uma Zapmizer anterior à 1.148.17 devolve. A lib o traduz com `Message::fromLegacy()` (`Messages\LegacyMessageTranslator`), um port da tradução da própria Zapmizer (`from-wwebjs.ts` e `toPartnerMessage()` do bot): o mesmo evento vira o mesmo `Message` que chegaria em `message_received`. Os testes da lib comparam campo a campo com as fixtures do servidor. O original continua em `$event->payload['data'][0]`.

- O que não dá para ler (sem id, sem tipo, um cartão de contato vazio) vira `unknown`, com o original em `raw`, como no contrato: nada some em silêncio.
- `fromMe` é sempre `false` aqui.

### O que a lib loga

- **Evento de mensagem que não traz mensagem** (`message` sem objeto em `data[0]`, `message_received` sem `id`): responde 200 (`Webhook Received`), não dispara nada e loga o warning `zapmizer: a message event could not be read, and nothing fired.` com `name`, `version`, `id`, `connection_id` e `wid`. Uma nova tentativa traria o mesmo corpo.
- **Versão desconhecida** (`message_received`/`message_sent` com `version` diferente de `2026-10-20`: mais nova, fora do formato de data ou ausente): a lib lê do mesmo jeito, porque o webhook não repete e descartar seria perder a mensagem. Se der um `Message`, o evento dispara; nos dois casos sai o warning `zapmizer: the webhook delivers a message in version …, which this version of the package does not know: …`. Atualize a lib.

### Fila

Um `MessageReceived` serializado por uma versão anterior da lib (listener `ShouldQueue`) não volta: ele carregava um `InboundMessage`, que não existe mais. Esvazie a fila desses listeners antes do deploy. O de/para do `InboundMessage` está no CHANGELOG ("Upgrade da 0.3 e da 0.4").

## `message_status`

```php
use NotificationChannels\Zapmizer\Events\MessageStatusUpdated;
use NotificationChannels\Zapmizer\Messages\MessageStatus;

Event::listen(function (MessageStatusUpdated $event) {
    $status = $event->status;

    $sent = SentWhatsapp::where('wapp_message_id', $status->wappMessageId)->first();

    if ($sent === null) {
        return; // mensagem que o app não conhece (enviada pelo celular, ou o id ainda não chegou)
    }

    if ($status->isFailed()) {
        $sent->update(['failed_at' => now()]);
    } elseif ($status->isRead()) {
        $sent->update(['read_at' => $status->occurredAt ?? now()]);
    }
});
```

- `ack` usa a mesma escala do acompanhamento do envio, `Messages\MessageStatus` (veja [envio.md](envio.md)): `-1` `FAILED`, `0` `SENDING`, `1` `SENT`, `2` `DELIVERED`, `3` `READ`, `4` `PLAYED`. Compare pelo número. No webhook só chegam de `-1` a `4`; `-2` (`QUEUED`), `-3` (`WAITING`) e `-4` (`FAILED_BEFORE_SENDING`) só aparecem no acompanhamento do envio.
- O `StatusUpdate` tem os mesmos atalhos do `SentMessage`: `isFailed()`, `isDelivered()`, `isRead()`, `isFinal()` e `messageStatusCode()` (o `ack`). `reached(MessageStatus::DELIVERED)` diz se a mensagem chegou pelo menos até ali, e nunca é verdade para uma que falhou.
- `status` é o nome que o webhook dá ao `ack`, sempre derivado dele: `0` chega como `pending`, os outros como `failed`, `sent`, `delivered`, `read` e `played`. Um evento com `status` `unknown`, ou com `ack` fora da escala, não dispara `MessageStatusUpdated`.
- O webhook não traz `wid_to` nem `links.self`: eles vêm na resposta do envio. O `wappMessageId` é o mesmo `wapp_message_id` que aparece no `links.self` quando o número envia. Um status pode chegar antes de você conhecer esse id: guarde os status sem dono por alguns minutos, ou consulte o `links.self` para fazer a ligação.
- As mensagens enviadas pelo celular também geram status. Ignore o `wappMessageId` que você não conhece.

## `connection`

```php
use NotificationChannels\Zapmizer\Events\ConnectionStateChanged;

Event::listen(function (ConnectionStateChanged $event) {
    $connection = $event->connection;

    if ($connection === null || !$event->isAttributable()) {
        // Sem wid não dá para saber de qual número é: não mude estado,
        // confirme pela API (is_online em GET /bot-instances/{id}).
        return;
    }

    $createdAt = $event->envelope->createdAt;
    $last = $connection->connectable->whatsapp_state_at;

    if ($createdAt === null || ($last !== null && $createdAt->lte($last))) {
        return; // evento mais velho que o último aplicado
    }

    $connection->connectable->forceFill([
        'whatsapp_state' => $event->state->state,
        'whatsapp_disconnect_kind' => $event->state->kind,
        'whatsapp_state_at' => $createdAt,
    ])->save();
});
```

- `state`: `qr_pending`, `connecting`, `connected` ou `disconnected` (`ConnectionState::*`, `isConnected()`, `isDisconnected()`). O `off` só existe na API, nunca no webhook. Um estado desconhecido não dispara o evento.
- `kind` só vem com `disconnected`: `banned` (bloqueado pelo WhatsApp), `logged_out` (precisa de QR novo), `conflict` (outra sessão do WhatsApp Web), `connection_lost` (religar resolve) ou `wrong_number` (o QR foi lido por outro número, a conexão foi recusada e o número volta a pedir QR). As constantes são `ConnectionState::KIND_*`. Na API, o mesmo campo se chama `disconnect_kind`. `reason` é o motivo como o WhatsApp informou.
- **`wid` nulo.** Vem nulo em `qr_pending` e `connecting` (antes de o número ficar pronto, inclusive ao religar) e pode vir em outros. O evento sai mesmo assim, com `isAttributable()` falso. Nesse caso não mude o estado da conexão por ele: confirme pela API.
- **O mais recente vale.** Cada ocorrência tem `id` próprio (duas quedas seguidas são dois eventos), e eles podem chegar fora de ordem. Aplique só o evento cujo `envelope->createdAt` é mais novo que o último aplicado.
- A lib não guarda o estado da conexão: as colunas do exemplo são do app.
- No número oficial, só chega `connection`, com `connected` e `disconnected`.

## Deduplicação

A Zapmizer reenvia uma entrega (até 3 vezes, só em 5xx ou erro de rede) sempre com o mesmo `id`. Por padrão a lib não deduplica, e um listener com efeito colateral precisa ser idempotente. Para a lib descartar as repetições:

```dotenv
ZAPMIZER_WEBHOOK_DEDUPLICATE=true
ZAPMIZER_WEBHOOK_DEDUPLICATE_TTL=21600   # segundos (padrão: 6 horas)
ZAPMIZER_WEBHOOK_DEDUPLICATE_STORE=redis # store do cache; vazio = o padrão
```

Quem publicou `config/zapmizer.php` acrescenta à mão, dentro de `webhook`, as chaves `deduplicate`, `deduplicate_ttl` e `deduplicate_store` (o merge da config é raso).

- Uma entrega com `id` já visto responde 200 (`Webhook Received`) e não dispara nada, nem o `WebhookReceived`.
- Duas fases. Antes de processar, o `id` é reservado com `Cache::add` por 120 segundos ("em processamento"). Depois de uma resposta que não é 5xx, ele é gravado pelo TTL cheio. Se a entrega falha (exceção ou resposta 5xx), a reserva é apagada e a retentativa passa. Um processo que morre no meio, sem exceção, só segura o `id` pelos 120 segundos.
- Uma retentativa que chega enquanto a primeira tentativa ainda roda recebe 200 e não dispara nada. Se a primeira falhar depois disso, o evento se perde: a Zapmizer não reenvia uma entrega respondida com 2xx.
- `deduplicate_ttl` vazio, zero ou negativo vale o padrão, 21600 segundos.
- A entrega descartada pela rota por conexão (o `wid` é de outro número) não chega ao controller e não reserva o `id`: a URL da conexão dona do número ainda processa o evento.
- **`connection` com `wid` nulo e várias rotas por conexão.** O mesmo evento do time chega à URL de cada conexão e, sem `wid`, nenhuma o descarta. Com a dedup ligada e um store compartilhado, só a primeira URL processa; as outras recebem 200 e não disparam nada. Como esse evento não muda estado (veja `connection`), isso só pesa para quem conta essas entregas por conexão.
- Entrega sem `id` (formato antigo) é sempre processada. `verify_number.*` também, porque chega sem assinatura: um `id` forjado ali não pode queimar o `id` de um evento real.
- Com o cache fora do ar, ou com um store que não existe na config, a entrega é processada e a lib loga um warning `zapmizer: webhook deduplication unavailable, delivery processed.`.
- Use um store compartilhado entre os servidores (Redis, banco). O `array` só vale dentro da requisição, e o `file` só vale numa máquina.

## Subclasses do `WebhookController`

- **`handle*` próprio.** Um `handleMessageStatus` (ou outro do catálogo) que o app já escreveu continua rodando, com a resposta dele e o `WebhookHandled`. O evento tipado sai também, antes dele. Para não ter os dois, escolha um.
- **`handleMessage()` sobrescrito.** Só roda em webhook `2024-02-27`. Em `2026-10-20`, o que o connect e o `createWebhook()` criam, a mensagem chega em `handleMessageReceived()`/`handleMessageSent()`: sobrescreva esses também, ou ouça `MessageReceived`/`MessageSent`. A lib registra um `Log::warning` a cada mensagem `2026-10-20` enquanto só o `handleMessage()` estiver sobrescrito. O mesmo vale para um listener de `WebhookReceived` que filtra `name === 'message'`.
- **`missingMethod` sobrescrito.** Continua recebendo os eventos do catálogo, como antes.
- **Trocar ou desligar eventos.** Sobrescreva `catalogEvents()`, que devolve o mapa `name => classe` (cada classe estende `WebhookEvent` e implementa `fromWebhook()`). Devolver `[]` desliga todos.
- **Resposta da repetição.** `duplicateMethod()` monta a resposta de uma entrega repetida.

```php
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;

class ZapmizerWebhookController extends WebhookController
{
    protected function catalogEvents(): array
    {
        return array_diff_key(parent::catalogEvents(), array_flip(['incoming_call']));
    }
}
```
