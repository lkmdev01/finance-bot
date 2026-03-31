# Checklist de Deploy no Coolify

Este projeto esta preparado para rodar no Coolify em um unico servico, com:

- Laravel web
- queue worker
- scheduler
- `whatsapp-service`

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

BAILEYS_SERVICE_URL=http://localhost:3001
BAILEYS_WEBHOOK_SECRET=SEU_SECRET_FORTE
WEBHOOK_SECRET=SEU_SECRET_FORTE
LARAVEL_URL=https://SEU_DOMINIO

AI_PROVIDER=groq
AI_API_KEY=SUA_CHAVE_IA
GROQ_MODEL=llama-3.1-8b-instant
```

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
3. Confirme que os processos subiram.
4. No primeiro deploy, escaneie o QR Code do WhatsApp nos logs.

## 9. Como validar

No terminal do container, rode:

```bash
supervisorctl status
```

O esperado e ver:

- `laravel-web` em `RUNNING`
- `laravel-worker` em `RUNNING`
- `laravel-scheduler` em `RUNNING`
- `whatsapp-service` em `RUNNING`

Valide tambem:

1. `GET /up`
2. `GET /health`
3. envio de uma mensagem de texto pelo WhatsApp
4. envio de uma mensagem de voz curta pelo WhatsApp

## 10. Problemas comuns

- `401 no webhook`
  `WEBHOOK_SECRET` e `BAILEYS_WEBHOOK_SECRET` estao diferentes.

- mensagem chega mas nao processa
  O `laravel-worker` nao subiu corretamente.

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
