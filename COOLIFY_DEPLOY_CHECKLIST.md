# Checklist de Deploy no Coolify

Este projeto esta preparado para rodar no Coolify em um unico servico, com:

- Laravel web
- queue worker
- scheduler
- `whatsapp-service`
- validacao comercial de billing no deploy

Tudo sobe junto pelo `start-all.sh`.

## 1. Banco de dados

1. Tenha um banco MySQL disponivel no Coolify.
2. Separe estes dados:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_DATABASE`
   - `DB_USERNAME`
   - `DB_PASSWORD`

## 2. Criar o servico

1. No Coolify, crie um servico chamado `financi-app`.
2. Aponte para o repositorio Git deste projeto.
3. Use `Nixpacks` como build pack, ou deixe a deteccao padrao.

## 3. Comando de start

No campo `Start Command`, use:

```bash
bash start-all.sh
```

## 4. Variaveis obrigatorias

Cadastre estas variaveis no servico `financi-app`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://SEU_DOMINIO
APP_KEY=base64:SUA_APP_KEY
APP_TIMEZONE=America/Sao_Paulo

DB_CONNECTION=mysql
DB_HOST=SEU_DB_HOST
DB_PORT=3306
DB_DATABASE=SEU_DB_DATABASE
DB_USERNAME=SEU_DB_USERNAME
DB_PASSWORD=SEU_DB_PASSWORD

QUEUE_CONNECTION=database
RUN_BILLING_SMOKE=true

BAILEYS_SERVICE_URL=http://localhost:3001
BAILEYS_WEBHOOK_SECRET=SEU_SECRET_FORTE
WEBHOOK_SECRET=SEU_SECRET_FORTE
LARAVEL_URL=https://SEU_DOMINIO

AI_USE_SDK=true
AI_PROVIDER=groq
AI_API_KEY=SUA_CHAVE_IA
GROQ_API_KEY=SUA_CHAVE_IA
GROQ_MODEL=openai/gpt-oss-20b
```

## 4.1. Acesso ao pacote privado InovaForce

O build instala `inovaforce/telemetry` a partir de um repositorio privado. Crie
um token fine-grained do GitHub limitado ao repositorio
`lkmdev01/inovaforce-telemetry`, com `Contents: Read-only`.

No Coolify, abra `Configuration > Environment Variables` e adicione a variavel
em modo Normal:

- chave: `COMPOSER_AUTH`
- valor: `{"github-oauth":{"github.com":"github_pat_SEU_TOKEN"}}`
- `Build Variable`: ligado
- `Runtime Variable`: desligado
- `Literal`: ligado

Em `Configuration > Advanced`, habilite `Use Docker Build Secrets` quando essa
opcao estiver disponivel. Nunca coloque o token no Git, em `.env.example` ou em
comandos registrados nos logs. Depois de alterar o segredo, execute um novo
deploy; se houver camada antiga em cache, use `Force deploy (without cache)`.

O repositorio do modulo usa o driver Git com URL HTTPS. O Composer clona a
versao fixada no `composer.lock` usando o token, sem depender de SSH dentro da
imagem.

## 5. Variaveis para voz e transcricao

Se quiser usar mensagens de voz com transcricao, adicione tambem:

```env
TRANSCRIPTION_PROVIDER=groq
TRANSCRIPTION_API_KEY=SUA_CHAVE_DE_TRANSCRICAO
TRANSCRIPTION_GROQ_MODEL=whisper-large-v3-turbo
```

Se preferir OpenAI para transcricao:

```env
TRANSCRIPTION_PROVIDER=openai
TRANSCRIPTION_API_KEY=SUA_CHAVE_OPENAI
TRANSCRIPTION_OPENAI_MODEL=whisper-1
```

Regras importantes:

- `WEBHOOK_SECRET` e `BAILEYS_WEBHOOK_SECRET` precisam ter exatamente o mesmo valor.
- `BAILEYS_SERVICE_URL` deve continuar como `http://localhost:3001` nesse modo monolito.
- `LARAVEL_URL` deve ser a URL publica do app.

## 6. Variaveis opcionais

OCR para imagem:

```env
GOOGLE_VISION_API_KEY=SUA_CHAVE_GOOGLE_VISION
```

Email, se for usar:

```env
MAIL_MAILER=smtp
MAIL_HOST=SEU_SMTP
MAIL_PORT=587
MAIL_USERNAME=SEU_USUARIO
MAIL_PASSWORD=SUA_SENHA
MAIL_FROM_ADDRESS=no-reply@SEU_DOMINIO
MAIL_FROM_NAME=Financi
```

## 7. Volume persistente do WhatsApp

Para nao perder a sessao do WhatsApp e nao precisar escanear QR a cada reinicio:

1. Abra a aba `Storage` no Coolify.
2. Crie um volume com:
   - `Source`: `financi_whatsapp_auth`
   - `Destination`: `/var/www/html/whatsapp-service/auth_info`

## 8. Deploy

Depois de salvar as variaveis:

1. Rode o deploy no Coolify.
2. Abra os logs do container.
3. Confirme que as etapas `Preparando Ambiente`, `Validando configuracao comercial/billing`, `Iniciando Workers e Scheduler`, `Iniciando WhatsApp Service` e `Iniciando Servidor Web Principal` apareceram sem erro.
4. No primeiro deploy, escaneie o QR Code do WhatsApp nos logs.

Configure o health check de ciclo de vida do Coolify para `GET /up`. O endpoint
`GET /health` verifica dependencias reais e retorna `503` quando fila ou WhatsApp
estao degradados; use-o em monitoramento/alerta, nao para reiniciar o container.

Antes de liberar dados reais, configure e teste o procedimento descrito em
`docs/BACKUP_AND_RESTORE.md`. Registre a data do ultimo teste em
`BACKUP_LAST_RESTORE_TEST_AT`.

## 9. Como validar

No terminal do container, rode:

```bash
php artisan billing:smoke
php artisan schedule:list
php artisan whatsapp:reliability
ps -ef | grep '[q]ueue:work'
tail -n 80 storage/logs/scheduler.log
tail -n 80 storage/logs/worker-1.log
```

O esperado e ver:

- `billing:smoke` passando.
- `billing:send-expiring-emails --days=3 --max-per-cycle=2` listado no scheduler.
- `whatsapp:reliability --mark-stale` listado no scheduler.
- nenhuma entrada ou saida inesperada marcada para revisao.
- a quantidade de processos `queue:work` corresponde a `QUEUE_WORKERS`.
- logs do scheduler sem erro.
- logs do worker sem erro.

Confirme tambem que os workers voltam automaticamente depois do sinal de restart:

```bash
php artisan queue:restart
sleep 5
ps -ef | grep '[q]ueue:work'
```

Valide tambem:

1. `GET /up`
2. `GET /health`
3. envio de uma mensagem de texto pelo WhatsApp
4. envio de uma mensagem de voz curta pelo WhatsApp

## 10. Problemas comuns

- `401 no webhook`
  `WEBHOOK_SECRET` e `BAILEYS_WEBHOOK_SECRET` estao diferentes.

- mensagem chega mas nao processa
  O worker iniciado pelo `start-all.sh` nao subiu corretamente. Confira `storage/logs/worker-1.log`.

- mensagem ou resposta ficou pendente
  Rode `php artisan whatsapp:reliability` e siga o runbook em `docs/WHATSAPP_RELIABILITY.md`. Nunca reprocesse manualmente uma entrada financeira sem conferir se a acao ja ocorreu.

- aviso de vencimento nao envia
  O scheduler nao esta rodando corretamente. Confira `storage/logs/scheduler.log` e `php artisan schedule:list`.

- deploy falha no `billing:smoke`
  Falta configurar AbacatePay v2, product ID mensal da oferta Pro, ou a oferta unica deixou de estar como assinatura recorrente.

- QR some a cada deploy
  O volume de `auth_info` nao esta configurado.

- audio nao transcreve
  Falta `TRANSCRIPTION_PROVIDER` ou `TRANSCRIPTION_API_KEY`, ou o provider nao aceita o modelo configurado.

- imagem nao extrai texto
  Falta `GOOGLE_VISION_API_KEY`.

## 11. Comando para gerar APP_KEY

Se ainda nao tiver a chave:

```bash
php artisan key:generate --show
```
