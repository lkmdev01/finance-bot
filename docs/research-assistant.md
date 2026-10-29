# Pesquisa web

## Fluxo

- `ResearchCommandService` reconhece solicitações explícitas antes do agente
  financeiro e recusa buscas sobre dados privados da conta.
- A consulta enviada ao SearXNG é separada do texto original, limitada em
  tamanho e sanitizada para remover e-mail, CPF, telefone e nome do usuário
  quando reconhecidos.
- A busca é executada pelo `RunWebResearch` na fila existente `financi`, com
  tentativas limitadas, backoff, unicidade por registro e lease para recuperar
  uma execução interrompida.
- `ResearchAnswerAgent` responde em português usando somente os resultados
  fornecidos e cita as fontes como `[n]`. As URLs são entregues separadamente
  no WhatsApp e ficam disponíveis no histórico autenticado `Pesquisas`.
- O pedido original, a cópia sanitizada e a resposta são criptografados no
  banco. Cada usuário acessa e exclui somente o próprio histórico.

## Configuração

Pesquisa desativada por padrão:

```dotenv
WEB_RESEARCH_ENABLED=false
SEARXNG_BASE_URL=
```

Para ativar após implantar e validar o serviço privado:

```dotenv
WEB_RESEARCH_ENABLED=true
SEARXNG_BASE_URL=http://inovafinance-searxng:8080
```

O SearXNG deve oferecer o formato JSON. A aplicação usa o provedor de IA já
configurado e contabiliza seu uso pelo controle mensal de tokens existente.
Não é necessário cadastrar uma credencial de API de busca.

## Operação e privacidade

O worker de filas existente deve consumir `financi`. Falhas são registradas
com códigos internos, sem consulta, resposta, credenciais ou conteúdo de
fontes. Não há expurgo automático do histórico; o usuário pode excluir cada
registro pelo painel.

SearXNG encaminha consultas para mecanismos externos, que podem impor limites
ou bloquear acessos. A sanitização reconhece identificadores comuns, mas não
garante detectar toda informação pessoal; o produto deve orientar usuários a
pesquisar apenas assuntos públicos.
