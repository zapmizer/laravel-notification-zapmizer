# Erros

Toda exceção que a lib lança estende `NotificationChannels\Zapmizer\Exceptions\ZapmizerException`, então um só `catch` pega todas:

```
ZapmizerException
├─ ZapmizerConnectException              connect flow and sending
│  ├─ ZapmizerApiException               Zapmizer refused the call (4xx)
│  │  ├─ ZapmizerRateLimitedException    429, retryAfter()
│  │  │  └─ MediaRateLimitedException
│  │  ├─ ZapmizerUnauthorizedException   401, or no token to send
│  │  ├─ PartnerCredentialsException     partner key refused (401/403)
│  │  ├─ MediaRejectedException          media request refused (422)
│  │  └─ InstanceGoneException           instance deleted (404)
│  ├─ ZapmizerUnavailableException       timeout, 5xx, no answer at all
│  └─ NoConnectableException             the resolver has nothing to connect
└─ ZapmizerVerificationException         verify-number
```

A `ZapmizerApiException` carrega o que a Zapmizer respondeu:

```php
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;

try {
    $team->zapmizerMessage('5511999999999')->text('Hello')->send();
} catch (ZapmizerUnauthorizedException $e) {
    // 401: o token foi revogado, ou não há token. Peça uma nova conexão.
} catch (ZapmizerRateLimitedException $e) {
    // 429: tente de novo em $e->retryAfter() segundos (null quando a Zapmizer não mandou Retry-After).
} catch (ZapmizerApiException $e) {
    if ($e->error() === ErrorCode::WINDOW_CLOSED) {
        $closedAt = $e->payload()['window_expires_at'] ?? null;
    }

    Log::warning($e->getMessage(), ['status' => $e->status(), 'errors' => $e->errors()]);
} catch (ZapmizerUnavailableException $e) {
    // Timeout ou 5xx: tente mais tarde.
}
```

- `status()`: o status HTTP.
- `error()`: o código da Zapmizer (`window_closed`, `bot_offline`, `recipient_not_found`, ...) ou `null`. É uma string simples, então um código que a Zapmizer acrescentar chega ao app sem release novo da lib; `ErrorCode` tem uma constante para cada código que a API documenta.
- `reason()`: o `message` da Zapmizer seguido do primeiro erro de cada campo; sem nenhum dos dois, o corpo cru, cortado em 500 caracteres.
- `errors()`: os erros de validação por campo, `array<string, string[]>`.
- `payload()`: o corpo JSON decodificado, com os campos extras (`window_expires_at`, ...).
- `getMessage()`: `Zapmizer refused the request (HTTP 409, window_closed): <reason>.`

## O que não é embrulhado

Falha que não é resposta da Zapmizer mantém a própria classe e **não é uma `ZapmizerException`**, então o `catch (ZapmizerException)` não a pega:

- `base_uri` malformada;
- campo multipart inválido;
- `Http::preventStrayRequests()` num teste;
- exceção lançada pelo seu transporte próprio;
- JSON com UTF-8 malformado no corpo (o `InvalidArgumentException` do Guzzle): é erro de quem chamou, não queda;
- `external_id` ou `state` inválido em `createSession()`, `subscription()` ou `checkout()` (`InvalidArgumentException`, antes de chamar a API; as regras estão em [connect.md](connect.md)).

## Resposta HTTP

Nenhuma dessas exceções renderiza resposta. As rotas de connect da lib respondem os próprios códigos JSON (veja [connect.md](connect.md)); em qualquer outro lugar quem decide é o handler de exceções do app, por `renderable()` (ou `withExceptions()` a partir do Laravel 11).
