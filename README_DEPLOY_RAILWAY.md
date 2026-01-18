# Deploy Laravel + Node.js no Railway

## 1. Pré-requisitos

- Conta Railway.app (https://railway.app)
- Projeto Laravel pronto (sem .env no repositório)
- whatsapp-service separado (Node.js)

## 2. Checklist de preparação

- [ ] Crie um arquivo `.env.example` com todas as variáveis necessárias
- [ ] Gere sua APP_KEY localmente: `php artisan key:generate --show`
- [ ] Teste localmente com `APP_ENV=production` e `APP_DEBUG=false`
- [ ] Adicione scripts de build no `package.json` e `composer.json` se necessário

## 3. Subindo o Laravel no Railway

1. Faça login no Railway e clique em "New Project" > "Deploy from GitHub repo"
2. Escolha seu repositório Laravel
3. No painel do projeto, vá em **Variables** e adicione as variáveis do `.env` (APP*KEY, DB*\*, QUEUE_CONNECTION, etc)
4. Crie um plugin de banco de dados (PostgreSQL ou MySQL) e copie as credenciais para as variáveis DB\_\*
5. Em **Deployments > Settings**:
    - Build command: `composer install --no-dev --optimize-autoloader && php artisan migrate --force && npm install && npm run build`
    - Start command: `php artisan serve --host=0.0.0.0 --port $PORT`
6. Rode `php artisan storage:link` após o deploy (pode ser via shell do Railway)
7. APP_URL deve ser a URL do Railway

## 4. Worker (Queue)

- Adicione um novo serviço no Railway ("New Service > Start from Repo" ou "Start from Dockerfile")
- Use o mesmo repositório, mas altere o start command para:
  `php artisan queue:work --tries=3`
- Use as mesmas variáveis de ambiente do web

## 5. whatsapp-service (Node.js)

- Suba como serviço separado no Railway
- Configure as variáveis de ambiente necessárias (endpoints, tokens, etc)
- Start command: `npm start` ou `node index.js`

## 6. Observações

- Pastas `storage` e `bootstrap/cache` precisam ser graváveis
- Se usar uploads, configure storage (local, S3, etc)
- Configure webhooks e endpoints externos para a URL do Railway
- Para e-mails, configure SMTP nas variáveis
- Para jobs agendados, use Railway Cron ou um serviço externo

## 7. Segurança

- Nunca suba `.env` para o repositório
- APP_DEBUG deve ser `false` em produção
- Use HTTPS sempre que possível

---

Dúvidas? Consulte a documentação do Railway ou peça ajuda aqui!
