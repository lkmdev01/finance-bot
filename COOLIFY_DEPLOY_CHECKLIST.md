# Checklist de Deploy no Coolify (VPS) - Versão Simplificada (Monolito)

Agora o projeto está configurado para rodar tudo em um único serviço usando Docker.

## 1. Preparar banco de dados

1. No Coolify, use o seu banco MySQL já criado.
2. Certifique-se de ter as credenciais do banco.

## 2. Criar serviço da aplicação

Crie apenas **1 serviço**:

1. `financi-app` (Serviço Privado de Repositório ou Público)

## 3. Configurar serviço `financi-app`

1. **Fonte**: Seu repositório Git.
2. **Build Pack**: Selecione `Dockerfile`.
3. **Porta**: O container expõe a porta `8000`. Configure o Coolify para mapear sua URL pública para a porta `8000`.

## 4. Variáveis de Ambiente

Configure as variáveis na aba "Environment Variables" do serviço no Coolify.

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://SEU_DOMINIO
APP_KEY=base64:SUA_KEY_AQUI

DB_CONNECTION=mysql
DB_HOST=mysql-database-rsc0o0scws8cgks0gwg08kos
DB_PORT=3306
DB_DATABASE=default
DB_USERNAME=mysql
DB_PASSWORD=SUA_SENHA_AQUI

BAILEYS_SERVICE_URL=http://localhost:3001
BAILEYS_WEBHOOK_SECRET=MESMO_DO_WEBHOOK_SECRET
WEBHOOK_SECRET=MESMO_DO_BAILEYS_WEBHOOK_SECRET
LARAVEL_URL=https://SEU_DOMINIO

AI_PROVIDER=groq
AI_API_KEY=...
GROQ_MODEL=llama-3.1-8b-instant
```

## 5. Volume Persistente (IMPORTANTE)

Para que o WhatsApp não precise ser escaneado toda vez que o container reiniciar:

1. Vá em **Storage** no Coolify.
2. Adicione um volume:
   - **Source**: `financi_whatsapp_auth`
   - **Destination**: `/var/www/html/whatsapp-service/auth_info`

## 6. Como verificar se está tudo rodando?

1. No terminal do container no Coolify, você pode rodar:
   `supervisorctl status`
2. Você deverá ver 4 processos operando:
   - `laravel-web`: RUNNING
   - `laravel-worker`: RUNNING
   - `laravel-scheduler`: RUNNING
   - `whatsapp-service`: RUNNING

