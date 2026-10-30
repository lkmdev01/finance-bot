# Avaliação de qualidade dos agentes

## Objetivo e escopo

Esta bateria usa exemplos fictícios e anonimizados para detectar regressões no roteamento de Pesquisas, Reuniões, Tarefas, Agenda e Financeiro. A matriz de roteamento usa dependências determinísticas mockadas e não chama LLMs, Google, WhatsApp, SearXNG ou Vexa.

Os testes de esclarecimento chamam os serviços reais de Agenda, Tarefas e Reuniões com parsers mockados. Eles verificam que campos faltantes geram perguntas claras e não acionam gravações ou submissões. Testes separados cobrem fallback de rate limit, limites por usuário e aplicação do tom. Esta bateria não tenta pontuar respostas abertas de um LLM; isso exigiria avaliações humanas ou um conjunto de respostas de referência revisado.

## Tarefas de manutenção

1. Manter exemplos de roteamento cross-domain em `tests/Fixtures/assistant_quality_scenarios.php`.
2. Cobrir mensagens incompletas e confirmar que a resposta pede somente os dados ausentes.
3. Manter cenários de provider rate limit para Financeiro, Agenda, Tarefas e Pesquisas, sem chamadas reais a provedores.
4. Verificar que a preferência de tom chega a todos os agentes e não altera ações estruturadas.
5. Executar os testes focados antes de release; após deploy, validar um caso de baixo risco por agente no WhatsApp.

Atualize os exemplos quando os contratos dos comandos mudarem. Use entidades fictícias e não inclua nomes, telefones, valores ou conteúdo de conversas reais.

## Execução local

```sh
php artisan test --compact \
  tests/Feature/AssistantScenarioEvaluationTest.php \
  tests/Feature/AssistantTonePreferenceTest.php \
  tests/Feature/WhatsAppAgendaRoutingTest.php \
  tests/Feature/WhatsAppTaskRoutingTest.php \
  tests/Unit/Meetings/MeetingCommandRoutingTest.php \
  tests/Feature/ResearchAssistantTest.php \
  tests/Feature/FinancialAgentIntegrationTest.php \
  tests/Feature/AiUserTokenBudgetTest.php \
  tests/Unit/Services/SpecializedAiFallbackServiceTest.php \
  tests/Unit/Services/WhatsAppMessageProcessorTest.php
```

Mocks evitam cobranças e chamadas externas. O smoke test pós-deploy fica manual: confirmar o commit implantado e enviar um pedido de leitura ou consulta sem criar/alterar dados, em cada domínio habilitado.
