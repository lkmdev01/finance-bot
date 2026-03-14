# Checklist de Deploy no Coolify (VPS)

Este documento e um passo a passo objetivo para publicar o projeto no Coolify.

## 1. Preparar banco de dados

1. No Coolify, crie um banco MySQL (ou PostgreSQL, se preferir ajustar o `.env`).
2. Anote as credenciais:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_DATABASE`
   - `DB_USERNAME`
   - `DB_PASSWORD`

## 2. Criar servicos da aplicacao

Crie 4 servicos:

1. `financi-web` (Laravel HTTP)
2. `financi-worker` (fila)
3. `financi-scheduler` (agendador)
4. `financi-whatsapp` (Node.js em `whatsapp-service`)

## 3. Configurar servico `financi-web`

1. Fonte: seu repositorio Git.
2. Build command:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
```

3. Start command:

```bash
php artisan serve --host=0.0.0.0 --port=$PORT
```

4. Defina dominio publico (HTTPS) para este servico.

## 4. Configurar servico `financi-worker`

1. Mesmo repositorio.
2. Build command:

```bash
composer install --no-dev --optimize-autoloader
```

3. Start command:

```bash
php artisan queue:work --tries=3 --timeout=120
```

4. Use as mesmas variaveis de ambiente do `financi-web`.

## 5. Configurar servico `financi-scheduler`

1. Mesmo repositorio.
2. Build command:

```bash
composer install --no-dev --optimize-autoloader
```

3. Start command:

```bash
php artisan schedule:work
```

4. Use as mesmas variaveis de ambiente do `financi-web`.

## 6. Configurar servico `financi-whatsapp`

1. Mesmo repositorio, com Root Directory: `whatsapp-service`.
2. Build command:

```bash
npm ci
```

3. Start command:

```bash
npm start
```

4. Configure volume persistente para pasta `auth_info/`.

## 7. Variaveis de ambiente

Use `.env.coolify.example` como base.

### 7.1 Laravel (`financi-web`, `financi-worker`, `financi-scheduler`)

Obrigatorias:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://SEU_DOMINIO
APP_KEY=base64:COLE_SUA_APP_KEY

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

QUEUE_CONNECTION=database

BAILEYS_SERVICE_URL=http://financi-whatsapp:3001
BAILEYS_WEBHOOK_SECRET=SECRET_FORTE

AI_PROVIDER=groq
AI_API_KEY=...
GROQ_MODEL=llama-3.1-8b-instant
```

### 7.2 Node (`financi-whatsapp`)

```env
WHATSAPP_SERVICE_PORT=3001
LARAVEL_URL=https://SEU_DOMINIO
WEBHOOK_SECRET=SECRET_FORTE
```

Regra critica:
- `WEBHOOK_SECRET` (Node) deve ser igual ao `BAILEYS_WEBHOOK_SECRET` (Laravel).

## 8. Ordem de deploy recomendada

1. Suba `financi-web`.
2. Suba `financi-worker`.
3. Suba `financi-scheduler`.
4. Suba `financi-whatsapp`.
5. Abra os logs do `financi-whatsapp` e escaneie o QR Code do WhatsApp.

## 9. Validacao apos subir

1. `GET https://SEU_DOMINIO/up` deve responder 200.
2. `GET https://SEU_DOMINIO/health` deve responder status `ok` ou `degraded` sem erro critico de DB.
3. Envie uma mensagem para o WhatsApp conectado e confirme:
   - webhook bate em `/webhook/whatsapp`;
   - job entra e sai da fila (`jobs`);
   - resposta volta no WhatsApp.

## 10. Problemas comuns

- `401 no webhook`: secrets diferentes entre Node e Laravel.
- Mensagem chega e nao processa: `financi-worker` parado.
- Rotinas diarias nao executam: `financi-scheduler` parado.
- QR aparece sempre: sem volume em `auth_info/`.
- Erro de arquivos publicos: faltou `php artisan storage:link`.

## 11. Comando para gerar APP_KEY

Execute localmente e copie para o Coolify:

```bash
php artisan key:generate --show
```
