# Guia de Contribuição

Obrigado por considerar contribuir para o FinanciApp! Este documento fornece diretrizes para contribuir com o projeto.

## 🚀 Setup do Ambiente

### Pré-requisitos

- PHP 8.4+
- Composer
- Node.js 18+
- MySQL 8.0+
- Laravel Herd (recomendado) ou servidor web local

### Instalação

1. Clone o repositório:
```bash
git clone https://github.com/seu-usuario/financi-app.git
cd financi-app
```

2. Instale as dependências PHP:
```bash
composer install
```

3. Instale as dependências Node.js:
```bash
npm install
```

4. Configure o ambiente:
```bash
cp .env.example .env
php artisan key:generate
```

5. Configure o banco de dados no `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=financi_app
DB_USERNAME=root
DB_PASSWORD=
```

6. Execute as migrações:
```bash
php artisan migrate
```

7. Compile os assets:
```bash
npm run build
# ou para desenvolvimento:
npm run dev
```

8. Configure o serviço WhatsApp (opcional):
```bash
cd whatsapp-service
npm install
cp .env.example .env
# Configure WEBHOOK_URL e WEBHOOK_SECRET
```

## 📝 Padrões de Código

### PHP

- Siga o [PSR-12](https://www.php-fig.org/psr/psr-12/)
- Use Laravel Pint para formatação:
```bash
vendor/bin/pint
```

### JavaScript

- Use ESLint e Prettier
- Siga as convenções do projeto

### Commits

- Use mensagens descritivas
- Siga o padrão: `tipo(escopo): descrição`
- Exemplos:
  - `feat(whatsapp): adiciona suporte a edição de transações`
  - `fix(ai): corrige parsing de valores monetários`
  - `docs(readme): atualiza instruções de instalação`

## 🧪 Testes

### Executar Testes

```bash
# Todos os testes
php artisan test

# Testes específicos
php artisan test --filter=AIServiceTest

# Com coverage
php artisan test --coverage
```

### Escrever Testes

- Use Pest para testes
- Testes devem ser descritivos
- Cobertura mínima: 70%

## 🎯 Como Adicionar Novas Funcionalidades

### 1. Criar Feature Branch

```bash
git checkout -b feature/nova-funcionalidade
```

### 2. Desenvolver

- Crie testes primeiro (TDD)
- Siga os padrões do projeto
- Documente código complexo

### 3. Testar

```bash
php artisan test
vendor/bin/pint
```

### 4. Commit e Push

```bash
git add .
git commit -m "feat(escopo): descrição"
git push origin feature/nova-funcionalidade
```

### 5. Criar Pull Request

- Descreva as mudanças
- Referencie issues relacionadas
- Adicione screenshots se aplicável

## 📚 Estrutura do Projeto

```
app/
├── Actions/          # Actions do Fortify
├── Console/          # Comandos Artisan
├── DataTransferObjects/  # DTOs
├── Exports/          # Exportações (Excel, PDF)
├── Http/
│   ├── Controllers/  # Controllers
│   └── Requests/     # Form Requests
├── Jobs/             # Queue Jobs
├── Livewire/         # Componentes Livewire
├── Models/           # Eloquent Models
├── Notifications/    # Notificações
├── Policies/         # Policies de autorização
├── Providers/        # Service Providers
├── Services/         # Services
└── ValueObjects/     # Value Objects
```

## 🔍 Code Review

- Seja respeitoso e construtivo
- Foque no código, não na pessoa
- Sugira melhorias específicas
- Aprenda com feedback

## 📖 Documentação

- Atualize README se necessário
- Documente APIs públicas
- Adicione comentários em código complexo

## ❓ Dúvidas?

- Abra uma issue
- Entre em contato com os mantenedores

Obrigado por contribuir! 🎉
