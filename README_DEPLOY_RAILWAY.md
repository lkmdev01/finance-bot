# Deploy Laravel + WhatsApp no Coolify (VPS)

Este projeto roda com Laravel (web), fila, scheduler e um servico Node separado (`whatsapp-service`).

## 1. Arquitetura recomendada no Coolify

Crie 4 servicos usando o mesmo repositorio (exceto quando indicado):

1. `financi-web` (Laravel HTTP)
2. `financi-worker` (queue worker)
3. `financi-scheduler` (scheduler)
4. `financi-whatsapp` (Node.js, pasta `whatsapp-service`)

Sem os servicos `worker` e `scheduler`, o sistema fica incompleto em producao.

## 2. Build e start commands

### 2.1 Servico `financi-web`

Build command:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
```

Start command:

```bash
php artisan serve --host=0.0.0.0 --port=$PORT
```

### 2.2 Servico `financi-worker`

Build command:

```bash
composer install --no-dev --optimize-autoloader
```

Start command:

```bash
php artisan queue:work --tries=3 --timeout=120
```

### 2.3 Servico `financi-scheduler`

Build command:

```bash
composer install --no-dev --optimize-autoloader
```

Start command:

```bash
php artisan schedule:work
```

### 2.4 Servico `financi-whatsapp`

Root/pasta do servico: `whatsapp-service`

Build command:

```bash
npm ci
```

Start command:

```bash
npm start
```

## 3. Variaveis de ambiente

## 3.1 Laravel (web/worker/scheduler)

Obrigatorias (minimo):

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://SEU_DOMINIO
APP_KEY=base64:...

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

QUEUE_CONNECTION=database

BAILEYS_SERVICE_URL=http://financi-whatsapp:3001
BAILEYS_WEBHOOK_SECRET=troque-por-um-secret-forte

AI_PROVIDER=groq
AI_API_KEY=...
GROQ_MODEL=llama-3.1-8b-instant
```

Notas:
- `BAILEYS_WEBHOOK_SECRET` deve ser igual ao `WEBHOOK_SECRET` do servico Node.
- Se usar Redis, ajuste `QUEUE_CONNECTION=redis` e variaveis `REDIS_*`.

## 3.2 WhatsApp service (Node)

```env
WHATSAPP_SERVICE_PORT=3001
LARAVEL_URL=https://SEU_DOMINIO
WEBHOOK_SECRET=troque-por-um-secret-forte
```

Notas:
- `LARAVEL_URL` precisa apontar para a URL do Laravel acessivel pelo servico Node.
- O endpoint usado e `/webhook/whatsapp`.

## 4. Persistencia da sessao WhatsApp

Configure um volume persistente no servico `financi-whatsapp` para manter a pasta:

- `auth_info/`

Sem isso, cada restart pede novo QR Code.

## 5. Checklist de validacao apos deploy

1. Verificar health:
   - `GET /up`
   - `GET /health`
2. Verificar rotas:
   - `POST /webhook/whatsapp`
3. Verificar fila:
   - jobs saindo da tabela `jobs`
4. Verificar logs:
   - `storage/logs/laravel.log`
5. Verificar status WhatsApp:
   - `GET /status` no servico Node

## 6. Problemas comuns

- `401 no webhook`: segredo diferente entre Laravel e Node.
- `mensagens chegam e nao processam`: `queue:work` nao esta rodando.
- `tarefas diarias nao executam`: `schedule:work` nao esta rodando.
- `QR sempre reaparece`: sem volume persistente em `auth_info/`.
- `arquivos nao abrem`: faltou `php artisan storage:link`.

## 7. Seguranca minima

- Nunca versionar `.env`.
- Manter `APP_DEBUG=false` em producao.
- Usar HTTPS no dominio publico.
- Usar segredo forte para webhook.
