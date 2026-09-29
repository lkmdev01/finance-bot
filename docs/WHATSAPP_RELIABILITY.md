# Confiabilidade do WhatsApp

Este fluxo separa o processamento da mensagem recebida da entrega da resposta. O objetivo e impedir que uma repeticao do webhook crie duas transacoes e permitir que uma resposta com falha seja recuperada sem executar novamente a acao financeira.

## Estados de entrada

- `received`: webhook aceito e job aguardando a fila.
- `processing`: um worker reservou a mensagem.
- `completed`: processamento encerrado.
- `needs_review`: o worker foi interrompido ou ocorreu uma excecao depois de iniciar uma acao.

O identificador da entrada e um hash do ID da mensagem, JID e participante enviados pelo Baileys. O corpo da mensagem nao e gravado na tabela de recibos.

## Estados de saida

- `pending`: resposta aguardando envio ou uma nova tentativa segura.
- `sending`: um worker reservou a entrega.
- `delivered`: o gateway confirmou o envio.
- `failed`: as tentativas seguras terminaram.
- `needs_review`: houve timeout, interrupcao ou erro ambiguo; a mensagem pode ter sido entregue.

O destinatario e o texto da resposta sao criptografados pelo cast `encrypted` do Laravel. A chave `APP_KEY` precisa ser preservada entre deploys para que respostas pendentes continuem legiveis pela aplicacao.

## Inspecao diaria

```bash
php artisan whatsapp:reliability
```

O comando mostra somente contagens e IDs internos. Ele nao imprime telefone, JID nem conteudo da conversa.

O scheduler executa a cada cinco minutos:

```bash
php artisan whatsapp:reliability --mark-stale
```

Entradas em processamento por mais de 15 minutos e saidas em envio por mais de dois minutos passam para `needs_review`. Nenhuma delas e reenviada automaticamente.

## Recuperar uma resposta

Uma saida `pending` ou `failed` pode ser reenfileirada sem repetir a acao financeira:

```bash
php artisan whatsapp:reliability --retry-outbound=ID
```

Para uma saida `needs_review`, primeiro confira no WhatsApp/Baileys se ela chegou. Somente quando houver certeza de que nao chegou, execute:

```bash
php artisan whatsapp:reliability --retry-outbound=ID --allow-ambiguous
```

O parametro `--allow-ambiguous` e uma confirmacao operacional explicita. Ele pode causar uma resposta duplicada se a verificacao no provedor estiver errada.

## Recuperar uma entrada

Uma entrada `needs_review` nunca deve ser reprocessada automaticamente. Verifique nesta ordem:

1. Consulte o `user_id`, o horario e o `review_reason` pelo ID interno.
2. Verifique se a transacao, nota, lembrete ou outra entidade ja foi criada.
3. Verifique se existe uma saida associada ao mesmo usuario e horario.
4. Corrija manualmente apenas o que estiver faltando.

Reexecutar o job original pode duplicar uma acao financeira, pois uma interrupcao pode ter acontecido depois do commit no banco.

## Deploy

O `start-all.sh` executa as migrations, inicia os workers e inicia `schedule:work`. Depois de cada deploy:

```bash
php artisan migrate:status
php artisan schedule:list
php artisan whatsapp:reliability
```

Faça testes com uma mensagem comum, uma criacao de despesa, uma repeticao do mesmo webhook e o Baileys desconectado. Confirme que a repeticao retorna `duplicate` e que nenhuma despesa adicional e criada.

## Rollback

O codigo anterior nao depende das tabelas novas, portanto um rollback da aplicacao pode manter as tabelas para auditoria. Nao execute `migrate:rollback` em producao antes de confirmar que nao existem respostas `pending`, `sending` ou `needs_review`.
