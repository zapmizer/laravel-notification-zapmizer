# Documentação do laravel-notification-zapmizer

Esta pasta vai junto com o código. Ao abrir pela tag que o app fixou, você lê a doc daquela versão.

1. [Enviar notificações](envio.md): o canal `zapmizer` numa `Notification`, o `ZapmizerMessage` e o formato do WID.
2. [Conectar um número por model](connect.md): o fluxo multi-tenant com `Connectable`, do popup de pareamento ao webhook assinado e à rotação do segredo (em inglês).
3. [Verificação de número do WhatsApp](verify-number.md): `MustVerifyWhatsapp`, a página hospedada, a confirmação do código e o webhook com o resultado (em inglês).
4. [Transporte HTTP, timeouts e testes](transporte.md): o `Http` do Laravel como transporte, os timeouts, o download de mídia, o fake nos testes e o transporte próprio.
5. [Erros](erros.md): a árvore de exceções, o que a `ZapmizerApiException` carrega e as falhas que não são embrulhadas.
