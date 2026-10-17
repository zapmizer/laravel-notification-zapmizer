# Eventos do webhook

A rota do webhook (`POST /zapmizer/webhook`, e a rota por conexão quando ligada) valida a assinatura e transforma cada evento do catálogo da Zapmizer num evento do Laravel. A lib não guarda nada: o app escuta e decide. A assinatura, os segredos e a rota por conexão estão em [connect.md §6](connect.md#6-receiving-messages-the-signed-webhook).

## Ordem dos eventos numa entrega

1. `WebhookReceived`, com o payload cru, para toda entrega que passou pela assinatura.
2. O evento tipado do catálogo (tabela abaixo), quando o `data` dá para ler.
3. O `handle{NomeEmStudly}` do controller, se existir. Para `message` é o da lib, que dispara `MessageReceived`; um `handle*` que o app escreveu numa subclasse roda aqui. Depois de um `handle*`, sai o `WebhookHandled`.

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

- `$event->envelope`: `id` (o mesmo em todas as tentativas da entrega), `name`, `createdAt` (`Carbon`, quando a Zapmizer gerou o evento) e `wid` (o número que gerou o evento, `null` em alguns `connection`);
- `$event->connection`: a `ZapmizerConnection` cujo segredo assinou a entrega, `null` no single-tenant;
- `$event->payload`: o corpo decodificado.

O `MessageReceived` também ganhou o `$event->envelope`.

### Leitura tolerante

Cada evento depende de um campo que o identifica: `wapp_message_id`, `call_id`, `old_wid` e `new_wid`, `group_wid`, `state` ou `ack`. Se esse campo falta, vem vazio ou tem tipo errado, o evento tipado não sai, e o resto da entrega segue igual (`WebhookReceived`, 2xx). Nos outros campos, um tipo errado vira `null`, `false` ou `[]`. Um valor novo de enum num campo secundário (`kind`, `scope`, `source`, `change`) chega como veio, sem precisar de release da lib.

Um `name` fora do catálogo não dispara nada novo.

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
- `kind` só vem com `disconnected`: `banned` (bloqueado pelo WhatsApp), `logged_out` (precisa de QR novo), `conflict` (outra sessão do WhatsApp Web) ou `connection_lost` (religar resolve). Na API, o mesmo campo se chama `disconnect_kind`. `reason` é o motivo como o WhatsApp informou.
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
- O `id` é reservado com `Cache::add` antes de processar. Se a entrega falha (exceção ou resposta 5xx), a reserva é liberada e a retentativa passa.
- Entrega sem `id` (formato antigo) é sempre processada. `verify_number.*` também, porque chega sem assinatura: um `id` forjado ali não pode queimar o `id` de um evento real.
- Com o cache fora do ar, ou com um store que não existe na config, a entrega é processada e a lib loga um warning `zapmizer: webhook deduplication unavailable, delivery processed.`.
- Use um store compartilhado entre os servidores (Redis, banco). O `array` só vale dentro da requisição, e o `file` só vale numa máquina.

## Subclasses do `WebhookController`

- **`handle*` próprio.** Um `handleMessageStatus` (ou outro do catálogo) que o app já escreveu continua rodando, com a resposta dele e o `WebhookHandled`. O evento tipado sai também, antes dele. Para não ter os dois, escolha um.
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
