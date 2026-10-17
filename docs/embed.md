# Embutir a conversa e a caixa de entrada

A Zapmizer abre a conversa de um contato, ou a caixa de entrada do número, num iframe dentro do seu app. O navegador usa o pacote `@zapmizer/embed`; o seu backend abre a sessão na Zapmizer e devolve ao front a resposta que ele espera. Esta página mostra o lado PHP: o `InstanceClient` abre a sessão e o `Http\Responses\EmbedSessionResponse` transforma o resultado (ou a recusa) no JSON do front.

## 1. Como funciona

```
@zapmizer/embed (navegador)
   │  POST /sua/rota                       (a rota é do app)
   ▼
controller do app
   │  decide o que é do app: integração, conexão, telefone
   ▼
InstanceClient::conversationSession() / inboxSession()   POST /api/embed/sessions
   ▼
EmbedSessionResponse                       200 {url, origin, resume_url, resume_until}
                                           ou {code} com o status certo
```

A lib não registra rota de embed. A rota precisa do telefone do contato, de quem é o usuário, da aparência validada, da permissão e do throttle do seu app, e nada disso se repete entre apps. O que se repete é a resposta, e é isso que a `EmbedSessionResponse` faz: o controller devolve o objeto (ele é `Responsable`) e o Laravel monta o JSON.

## 2. A rota do app

```php
<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;

class ConversationEmbedController
{
    public function __invoke(Request $request, Customer $customer): Responsable
    {
        $user = $request->user();
        $connection = $user->currentTeam->zapmizerConnection;

        abort_if($connection === null, 404);

        if (blank($customer->whatsapp_number)) {
            return EmbedSessionResponse::code('customer_without_phone');
        }

        // Opcional: registra a recusa da Zapmizer (veja a seção 6).
        $logRefusal = fn (ZapmizerApiException $refusal) => logger()->warning('zapmizer_embed_refused', [
            'status' => $refusal->status(),
            'code' => $refusal->error(),
        ]);

        return EmbedSessionResponse::open(
            fn () => $connection->instanceClient()->conversationSession(
                phone: $customer->whatsapp_number,
                parentOrigin: $request->getSchemeAndHttpHost(),
                appearance: $request->only(['theme', 'color_primary']),
                userId: (string) $user->id,
                userName: $user->name,
                userEmail: $user->email,
            ),
            onRefusal: $logRefusal,
        );
    }
}
```

```php
use App\Http\Controllers\ConversationEmbedController;
use Illuminate\Support\Facades\Route;

Route::post('customers/{customer}/zapmizer/conversation', ConversationEmbedController::class)
    ->middleware(['auth', 'throttle:30,1']);
```

A regra: **o que é do app se decide antes do `open()`**, e dentro da closure vai só a chamada à Zapmizer.

- Integração, conexão e telefone são do app: `abort_if(..., 404)` e `EmbedSessionResponse::code('customer_without_phone')` ficam fora da closure.
- Dentro da closure vai inclusive a resolução do cliente (`$connection->instanceClient()`). Assim, o token ausente vira `reauth_required` (o `instanceClient()` lança a `ZapmizerUnauthorizedException` de dentro da closure e o `open()` a trata), e qualquer `ZapmizerConnectException` lançada dentro da closure vira 503 `unavailable` com `report()`, em vez de estourar como 500. Por isso o exemplo só dá 404 quando não existe conexão nenhuma, e não confere o token fora da closure.

A rota fica atrás do `auth` e de um `throttle`. O CSRF e o keepalive da sessão do app são do front: veja a doc do `@zapmizer/embed` (`docs/backend.md`) para o que o navegador envia.

## 3. Quem atende: `user`

Mande um `user.id` estável: a chave do usuário no seu app, a mesma entre reconexões e entre sessões.

- Com `user.id`, a pessoa vira atendente na equipe do cliente, só com acesso ao número. Na conversa, a mensagem sai em nome dela, a automação do contato pausa e o ticket é aberto ou atribuído a ela. Na caixa, tudo o que ela faz no iframe fica em nome dela, e a caixa fecha com `session_revoked` quando ela sai do time ou perde o acesso ao número.
- Sem `user.id`, tudo fica em nome de quem aprovou a conexão.
- `user.name` vira o nome do atendente.
- `userEmail` é opcional e só serve às exportações. A lib faz `trim` e só envia um e-mail que passe no `FILTER_VALIDATE_EMAIL`; um inválido (inclusive acima de 254 bytes) sai do corpo, sem erro e sem log, para não derrubar a sessão inteira com um 422.
- `user.id` e `user.name` acima de 100 caracteres dão 422.

## 4. A resposta

| Desfecho | Status | Corpo | Cabeçalho |
| --- | --- | --- | --- |
| sessão aberta | 200 | `{url, origin, resume_url, resume_until}` | |
| `code()` do app | o que você passar (422 por padrão) | `{code}` | |
| 401 ou 419 da Zapmizer | 422 | `{"code": "reauth_required"}` | |
| 429 | 429 | `{"code": "rate_limited"}` | `Retry-After`, só quando a Zapmizer mandou um inteiro |
| outra recusa 4xx | o mesmo status da Zapmizer | `{"code": <error>}` | |
| 5xx, rede, resposta inesperada, configuração inválida | 503 | `{"code": "unavailable"}` | |

- A resposta **nunca** é 401 nem 419: o front trata esses status como sessão do app expirada. A reautorização sai como 422.
- O `Retry-After` só sai quando é um inteiro, e `Retry-After: 0` sai. Uma data HTTP ou outro formato não vai.
- `{"code": null}` sai com a chave quando a Zapmizer recusa sem `error`.
- `resume_until` sai com o fuso que a Zapmizer mandou (`+00:00`), sem converter para o fuso do app. `expires_at` não vai.
- A queda da Zapmizer (5xx, rede) não é reportada: é passageira e o front tenta de novo. A resposta inesperada e a configuração inválida (`base_uri` ou outra `ZapmizerConnectException`) passam pelo `report()` do Laravel, que registra a causa.
- `403 missing_ability` e `403 not_a_partner_connection` chegam ao front como vieram, e ele não tem ação nem texto padrão para eles. O app que quer o botão de reconectar nesses dois casos troca o código na seção 7.

## 5. Código próprio do app

```php
EmbedSessionResponse::code('customer_without_phone');          // 422
EmbedSessionResponse::code('integration_disabled', 409);
```

O `code()` responde `{code}` com o status dado, no mesmo formato das recusas da Zapmizer. Ele recusa (`InvalidArgumentException`) um código vazio e um status 401, 419 ou fora de 400 a 599, pela mesma razão do 401/419 acima.

## 6. Registrar a recusa

```php
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;

EmbedSessionResponse::open(
    fn () => $connection->instanceClient()->inboxSession($request->getSchemeAndHttpHost()),
    onRefusal: function (ZapmizerApiException $refusal) use ($team) {
        logger()->warning('zapmizer_embed_refused', [
            'team_id' => $team->id,
            'status' => $refusal->status(),
            'code' => $refusal->error(),
        ]);

        if ($refusal->error() === ErrorCode::SUBSCRIPTION_REQUIRED) {
            rescue(fn () => $team->markSubscriptionRequired());
        }
    },
);
```

O `onRefusal` roda uma vez em toda recusa da Zapmizer, antes de montar a resposta, e recebe a `ZapmizerApiException`. Isso inclui o 401 (o app pode querer marcar a conexão como caída) e o 429. Fora dele ficam a queda da Zapmizer e a resposta inesperada, que seguem para o `report()`.

- O retorno do gancho é ignorado; devolver uma `Response` ou `false` não muda a resposta.
- Uma exceção do gancho propaga. Para o que for melhor esforço (gravar uma flag, avisar alguém), embrulhe com `rescue()` como acima, para que uma falha dali não esconda a resposta ao front.
- Num `subscription_required`, o caminho do cliente é o checkout: `PartnerClient::checkout($externalId, $redirectUri)` devolve um `PartnerCheckout` com a `url` (veja [connect.md](connect.md)).

## 7. Mudar o mapeamento

A classe não é `final`. Uma subclasse troca a política de tela sobrescrevendo `refusalStatus()` e `refusalCode()` (a closure `open()` e o `code()` são herdados), ou o corpo do sucesso com `sessionPayload()`.

Exemplo: normalizar toda recusa conhecida para 422, e fazer `missing_ability` e `not_a_partner_connection` caírem na reautorização.

```php
<?php

namespace App\Http\Responses;

use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;

final class ScreenEmbedResponse extends EmbedSessionResponse
{
    protected function refusalCode(ZapmizerApiException $refusal): ?string
    {
        if (! in_array($refusal->status(), [402, 403, 422], true)) {
            return self::UNAVAILABLE;
        }

        return match ($refusal->error()) {
            null => [403 => self::REAUTH_REQUIRED, 422 => 'rejected'][$refusal->status()] ?? self::UNAVAILABLE,
            ErrorCode::MISSING_ABILITY, ErrorCode::NOT_A_PARTNER_CONNECTION => self::REAUTH_REQUIRED,
            default => $refusal->error(),
        };
    }

    protected function refusalStatus(ZapmizerApiException $refusal): int
    {
        return $this->refusalCode($refusal) === self::UNAVAILABLE ? 503 : 422;
    }
}
```

No controller, troque `EmbedSessionResponse::open(...)` por `ScreenEmbedResponse::open(...)`.

- O 401, o 419 e o 429 são regras fixas do protocolo do front e **não** passam por `refusalStatus()`/`refusalCode()`.
- Se `refusalStatus()` devolver um status fora de 400 a 599, ou 401 ou 419, o `toResponse()` lança `UnexpectedValueException`. A reautorização nunca vira 401/419, nem por uma subclasse.
- Para um campo a mais no sucesso, sobrescreva `sessionPayload(EmbedSession $session): array` e devolva `parent::sessionPayload($session) + ['campo' => ...]`.

## 8. O cliente

Quem prefere montar a resposta sozinho usa o `InstanceClient` direto (é o que o `open()` chama). Pegue o cliente da conexão:

```php
$client = $connection->instanceClient();

$conversation = $client->conversationSession(
    phone: '5521988887777',
    parentOrigin: 'https://app.example.com',
    appearance: ['theme' => 'dark', 'color_primary' => '#2E6BFF'],
    userId: (string) $user->id,
    userName: $user->name,
    userEmail: $user->email,
);

$inbox = $client->inboxSession(
    parentOrigin: 'https://app.example.com',
    appearance: ['theme' => 'dark', 'color_accent' => '#7FA6FF', 'dark_background' => '#141a24'],
    userId: (string) $user->id,
    userName: $user->name,
    userEmail: $user->email,
);
```

- `conversationSession(string $phone, string $parentOrigin, array $appearance = [], ?string $userId = null, ?string $userName = null, ?string $userEmail = null): EmbedSession` e `inboxSession(string $parentOrigin, array $appearance = [], ?string $userId = null, ?string $userName = null, ?string $userEmail = null): EmbedSession` fazem `POST /api/embed/sessions` com `component` `conversation` ou `inbox`. O `userEmail` é o último parâmetro, então quem chama por posição não quebra.
- `appearance` vai nos dois componentes, sem os valores `null` e só quando sobra algo; a lib não valida (a Zapmizer responde 422). Os campos são `theme`, `color_primary`, `color_accent`, `radius` e `font_family`, mais `dark_background` só na caixa (na conversa é aceito e ignorado). As cores são `#RRGGBB`. Um `dark_background` que não seja escuro o bastante é um 422 com o campo em `errors()`.
- `user` leva o id, o nome e o e-mail que não sejam `null` nem brancos, e some quando nenhum sobra. `phone` e `parent_origin` vão como foram dados.
- `EmbedSession` traz `url` (abra no iframe em até 60 segundos; ela abre uma vez, nunca a guarde em cache), `origin` (a origem do iframe como o navegador a serializa: minúscula, sem a porta padrão; compare `event.origin` com ela), `expiresAt` e, na caixa, `resumeUrl` e `resumeUntil` (guarde a `resumeUrl` por pessoa e carregue-a até `resumeUntil`). `resumeUntil` é `null` sempre que `resumeUrl` for `null`, inclusive quando a Zapmizer mandou um `resume_until` mas o `resume_url` veio ausente, inseguro ou de outra origem. As datas são `?CarbonImmutable`.
- A `url` precisa ser segura para o iframe: `http` ou `https`, sem usuário nem senha, sem espaço, caractere de controle ou barra invertida, porta de 1 a 65535, e host ASCII (IDN só em punycode) ou IPv6 entre colchetes. Qualquer outra coisa é `unexpectedResponse`. Um `resume_url` inseguro, ou de outra origem que a `url`, vira `null` e loga o warning `zapmizer: unexpected resume url.` só com `resume_origin` e `url_origin`, nunca a URL.
- As recusas são `ZapmizerApiException` com `status()`, `error()` e `errors()`: 402 `subscription_required` (checkout); 403 `not_a_partner_connection`, `origin_not_allowed` ou `missing_ability` (a caixa precisa de um connect feito depois que a habilidade existia: conecte de novo); 422 `connection_without_number`, `number_unavailable`, `approver_without_access`, ou os erros de campo. O 401 é uma `ZapmizerUnauthorizedException`, o 429 uma `ZapmizerRateLimitedException`, o 5xx uma `ZapmizerUnavailableException`, e qualquer resposta que não seja um 201 com `url` segura é `unexpectedResponse`. A ordem dos `catch` está em [connect.md](connect.md) ("Catch order").
- Uma `base_uri` que não seja uma URL http(s) absoluta, com host e sem `user:pass`, lança `ZapmizerConnectException::invalidBaseUri()` antes de qualquer envio (veja [erros.md](erros.md)). O `open()` a trata como 503 `unavailable` com `report()`.
- Uma subclasse de `InstanceClient` que sobrescreve `conversationSession()` ou `inboxSession()` precisa aceitar o `$userEmail`, senão dá erro fatal de assinatura.

## 9. Testes

Fake a Zapmizer com `Http::fake` e confira o JSON da sua rota:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationEmbedTest extends TestCase
{
    public function test_it_answers_the_session_the_front_expects()
    {
        Http::fake([
            '*/embed/sessions' => Http::response([
                'url' => 'https://app.parlichat.com/embed/start/Zr8kQ2',
                'expires_at' => '2026-09-26T14:01:00+00:00',
            ], 201),
        ]);

        $this->actingAs($this->userOfAConnectedTeam())
            ->postJson('/customers/1/zapmizer/conversation')
            ->assertOk()
            ->assertExactJson([
                'url' => 'https://app.parlichat.com/embed/start/Zr8kQ2',
                'origin' => 'https://app.parlichat.com',
                'resume_url' => null,
                'resume_until' => null,
            ]);
    }

    public function test_a_revoked_token_asks_for_a_new_connect()
    {
        Http::fake(['*/embed/sessions' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs($this->userOfAConnectedTeam())
            ->postJson('/customers/1/zapmizer/conversation')
            ->assertStatus(422)
            ->assertExactJson(['code' => 'reauth_required']);
    }
}
```

`userOfAConnectedTeam()` é o seu helper (um usuário cuja equipe tem uma `ZapmizerConnection` conectada). Sem a resposta da Zapmizer no fake, um `Http::fake()` genérico devolve 200 vazio: a lib trata isso como resposta inesperada, reporta e responde 503 `unavailable`. Para provar que nada saiu, use `Http::assertNothingSent()`. O fake do transporte está em [transporte.md](transporte.md).
