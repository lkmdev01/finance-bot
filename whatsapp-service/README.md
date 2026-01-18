# Serviço WhatsApp com Baileys

Este serviço Node.js conecta ao WhatsApp Web usando Baileys e se comunica com o Laravel.

## Instalação

```bash
cd whatsapp-service
npm install
```

## Configuração

Crie um arquivo `.env` ou configure as variáveis de ambiente:

```env
WHATSAPP_SERVICE_PORT=3001
LARAVEL_URL=http://localhost:8000
WEBHOOK_SECRET=your-secret-key
```

## Execução

```bash
npm start
```

Ou em modo desenvolvimento (com auto-reload):

```bash
npm run dev
```

## Como Funciona

1. Ao iniciar, o serviço gera um QR Code
2. Escaneie o QR Code com o WhatsApp
3. O serviço escuta mensagens recebidas
4. Quando recebe uma mensagem, envia um webhook HTTP para o Laravel
5. O Laravel pode enviar mensagens fazendo POST para `/send-message`

## API

### POST /send-message
Envia uma mensagem via WhatsApp

```json
{
  "phone": "5511999999999",
  "message": "Olá!",
  "secret": "your-secret-key"
}
```

### GET /status
Verifica status da conexão

```json
{
  "connected": true
}
```

