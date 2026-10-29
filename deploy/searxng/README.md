# SearXNG privado no Coolify

Esta composição prepara o SearXNG no mesmo servidor Coolify do Laravel. O
SearXNG recebe um alias na rede compartilhada `coolify`; o Valkey permanece
somente na rede privada do serviço. Não há portas publicadas nem domínio.

## Configuração

1. Crie um serviço **Docker Compose Empty** e cole `docker-compose.yml`.
   A propriedade `content:` cria o arquivo `settings.yml` no bind mount e é
   uma extensão do Coolify.
2. Cadastre `SEARXNG_VERSION` com uma tag fixa da imagem e `SEARXNG_SECRET`
   como segredo no Coolify. Não registre o segredo no Git.
3. Mantenha `Connect To Predefined Network` desmarcado: o Compose conecta
   apenas `core` à rede externa `coolify`, e mantém Valkey na rede do serviço.
4. Não configure domínio ou portas públicas. Preserve os volumes `core-data`
   e `valkey-data` em atualizações.

O endpoint JSON está habilitado em `search.formats`. O limiter do SearXNG está
desativado porque chamadas internas do Laravel não passam por um proxy que
forneça `X-Forwarded-For`/`X-Real-IP`; o serviço não deve ser publicado fora da
rede Docker. O limiter pode ser reavaliado se a rede ou o modelo de acesso
mudar.

## Laravel

Configure no ambiente Laravel:

```dotenv
SEARXNG_BASE_URL=http://inovafinance-searxng:8080
WEB_RESEARCH_ENABLED=false
```

Habilite a pesquisa somente após o código correspondente estar implantado e
testado. `RunWebResearch` usa a fila existente `financi`.

## Verificação

No terminal do contêiner Laravel:

```bash
php artisan tinker --execute='$r=Illuminate\Support\Facades\Http::timeout(20)->get("http://inovafinance-searxng:8080/search", ["q"=>"teste","format"=>"json"]); echo "HTTP ".$r->status()." | JSON=".($r->json("results") !== null ? "ok" : "ausente").PHP_EOL;'
```

O esperado é `HTTP 200 | JSON=ok`. Use somente consultas públicas genéricas.

Confirme também que `docker ps` não mostra mapeamento de portas para os
contêineres SearXNG e Valkey. A rede `coolify` é compartilhada pelos recursos
no mesmo servidor; por isso a composição não deve receber domínio ou portas.

## Privacidade e operação

SearXNG consulta mecanismos externos. Não envie consultas com nome, telefone,
e-mail, documentos, dados financeiros ou contexto de conta. O Laravel sanitiza
identificadores reconhecíveis e não anexa histórico financeiro. Mecanismos
upstream podem limitar ou bloquear consultas; monitore respostas vazias e
falhas nos logs sem registrar consultas privadas.
