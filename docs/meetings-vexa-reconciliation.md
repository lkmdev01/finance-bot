# Reconciliação e encerramento seguro de reuniões

## Comportamento

`meetings:reconcile-vexa` consulta a API REST do Vexa para reuniões nos estados de entrada, `active`, `stopping` e falhas locais ainda não confirmadas pelo Vexa. A rotina:

- atualiza estados não terminais somente a partir de estados Vexa reconhecidos;
- quando o Vexa informa `completed`, move o registro para `awaiting_transcription` e cria ou recupera a tarefa usando a chave única `meeting_id`;
- não inicia transcrição após apenas receber HTTP 200 da parada: aguarda a confirmação remota `completed`;
- não usa silêncio, ausência de segmentos ou `updated_at` como prova de que participantes saíram;
- no teto de duração, envia um aviso, solicita a parada pelo Vexa e tenta novamente se o bot continuar ativo após o intervalo configurado;
- registra apenas ID interno da reunião, motivo, contagens e classe da exceção. Não inclui URL, ID nativo, áudio, transcrição, credencial ou chave.

O scheduler executa a rotina a cada cinco minutos, com prevenção de sobreposição. O `start-all.sh` já inicia `php artisan schedule:work`, portanto o mecanismo está presente no código da imagem. Isso não confirma que o processo está ativo no Coolify agora. Após publicar, verifique no container de produção os logs de `scheduler.log`/`schedule:work`, execute `php artisan schedule:list` e confirme uma execução de `php artisan meetings:reconcile-vexa`. O comando não consulta nem modifica o Vexa quando `VEXA_ENABLED=false`.

## Limite máximo de duração

- `VEXA_MAX_MEETING_DURATION_MINUTES`: padrão `240`; limite efetivo de 30 a 720 minutos.
- `VEXA_MAX_MEETING_DURATION_WARNING_MINUTES`: padrão `15`; limite efetivo de 1 a 60 minutos, sempre abaixo do limite total.
- `VEXA_STOP_RETRY_SECONDS`: padrão `180`; limite efetivo de 60 a 900 segundos.

O `active_since` prefere `start_time` ou `started_at` retornado pelo Vexa. Se esses campos faltarem, usa o primeiro instante em que o Laravel observa a reunião ativa. Para reuniões que já estavam ativas antes da migration, o limite pode começar na primeira reconciliação. É uma salvaguarda operacional, não uma inferência de que as pessoas saíram.

Ao atingir o teto, Laravel marca `stopping` e chama `DELETE /bots/{platform}/{native_meeting_id}`. HTTP 200 é somente aceite do pedido. Laravel continua consultando o estado remoto; `meeting_transcription_tasks` só é criada depois de `completed`, pela mesma rotina idempotente usada pelos webhooks e pelo polling atual.

## Encerramento pelo WhatsApp

O remetente pode enviar `encerrar reunião`, `encerrar reuniao`, `parar reunião` ou `finalizar reunião`. A seleção só consulta reuniões `active` vinculadas ao `user_id` do remetente. Se houver mais de uma ativa, nenhuma é encerrada por ambiguidade. O job usa o `platform` e o ID nativo já armazenados e criptografados para chamar o endpoint oficial. Durante a parada, a resposta informa que a transcrição aguardará a gravação ser finalizada.

## Limitação de presença no Microsoft Teams

O container local `vexaai/vexa-lite:v012` está executando com o digest `sha256:9eebb534fdab4079baf11af678968ac8d9679e20ce48db206bcf5a62b85eb538`, que corresponde ao artefato publicado do Vexa Lite `v0.12.28`. Não reiniciei nem atualizei o container.

Nas notas oficiais, o recurso “leave when alone” de `v0.12.16` foi testemunhado em Google Meet; as mesmas notas explicitam que essa detecção não cobria Teams. `v0.12.24`/`v0.12.25` corrigiram falhas de captura, recuperação durante a saída e adicionaram leitura de participantes, mas essa leitura é roster histórico e pode declarar `observed_roster: not_recorded`; não é um sinal de ocupação em tempo real. `v0.12.28` não documenta uma nova detecção de saída de participantes no Teams. Portanto, o comportamento observado não é corrigido por uma atualização que possamos comprovar nas notas dessas versões.

Sem uma API de presença confiável do Vexa/Teams, não encerramos por silêncio. O teto de duração controla gravações indefinidas e o comando WhatsApp permite encerramento explícito. A detecção automática de saída do Teams continua sem validação real e não é declarada resolvida.

Referências oficiais consultadas em 2026-10-24:

- [Releases do Vexa](https://github.com/Vexa-ai/vexa/releases), em especial [v0.12.16](https://github.com/Vexa-ai/vexa/releases/tag/v0.12.16), [v0.12.24](https://github.com/Vexa-ai/vexa/releases/tag/v0.12.24), [v0.12.25](https://github.com/Vexa-ai/vexa/releases/tag/v0.12.25) e [v0.12.28](https://github.com/Vexa-ai/vexa/releases/tag/v0.12.28).
- [API REST de reuniões](https://docs.vexa.ai/api/meetings), incluindo estados e endpoint oficial de parada.

## Operação

Depois de aprovar e publicar as alterações, aplique a migration `2026_10_24_000001_add_meeting_reconciliation_fields.php` pelo processo normal de deploy Laravel. Confirme que os workers Redis continuam atendendo a fila padrão configurada pela aplicação (`financi` na configuração de produção previamente observada), pois `StopMeetingBot` e o aviso de limite são Jobs Laravel.

Validação controlada: em uma reunião de teste com participantes avisados, confirme `active`, envie `encerrar reunião`, confirme a resposta Vexa `stopping`, aguarde `completed` e verifique que existe uma única tarefa `meeting_transcription_tasks`. Teste o teto com relógio congelado nos testes; não aguarde o teto em reunião real.

Rollback: reverta o release da aplicação. Antes de reverter a migration, avalie a preservação dos metadados de reconciliação. O `down` remove somente as colunas novas; não exclui gravações, transcrições ou linhas de reuniões no Vexa.
