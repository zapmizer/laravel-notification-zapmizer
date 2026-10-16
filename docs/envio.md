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
