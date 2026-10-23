# Worker local de transcrição do InovaFinance

O worker consulta o Laravel por HTTPS de saída, reivindica uma tarefa com lease, busca somente a gravação concluída pela API do Vexa e executa `faster-whisper` no Fedora. Ele não abre porta, não acessa Redis e não envia áudio para o Laravel ou para uma API de transcrição. O Laravel ainda consulta o estado da reunião pelo endpoint Vexa `/meetings/{id}`, que na versão atual requer a permissão `tx`; essa permissão é usada para ler o estado, não para transcrever.

## Configuração

1. Use um ambiente Python local em que `faster-whisper` e seus requisitos de áudio estejam instalados. O modelo padrão é `Systran/faster-whisper-small`; configure um diretório/modelo já disponível localmente se não quiser baixar os pesos durante a primeira execução.
2. Copie `meeting-transcription-worker.env.example` para `/etc/inovafinance/meeting-transcriber.env` e preencha as URLs e credenciais diretamente no Fedora. Não coloque o arquivo real no repositório.
3. Crie o segredo `VEXA_TRANSCRIPTION_WORKER_SECRET` com ao menos 32 bytes aleatórios e configure o mesmo segredo no ambiente Laravel como `VEXA_TRANSCRIPTION_WORKER_SECRET`.
4. Restrinja o arquivo de ambiente ao usuário do serviço (`root` como proprietário e leitura apenas pelo grupo/usuário autorizado; por exemplo, modo `0640`). O worker não imprime o conteúdo do ambiente.
5. Garanta a existência do usuário de serviço `inovafinance`, ajuste `ExecStart` em `inovafinance-vexa-transcriber.service` para o Python do ambiente acima e instale a unit no systemd. A unit usa `PrivateTmp`, `UMask=0077`, concorrência um e diretórios graváveis limitados.
6. Inicie o serviço somente depois de publicar a aplicação e confirmar os endpoints autenticados. Esta documentação não inicia serviços.

## Protocolo

Todas as chamadas ao Laravel são `POST` e assinam método, caminho, timestamp Unix, nonce e SHA-256 do corpo com HMAC-SHA256. Nonces repetidos são rejeitados. A API também exige `X-Meeting-Lease` para heartbeat, resultado e falha.

- `POST /api/v1/internal/meeting-transcriptions/claim`: entrega uma tarefa (`204` quando vazia).
- `POST /api/v1/internal/meeting-transcriptions/{task}/heartbeat`: renova o lease ativo.
- `POST /api/v1/internal/meeting-transcriptions/{task}/result`: envia segmentos com timestamps do áudio original e metadados mínimos.
- `POST /api/v1/internal/meeting-transcriptions/{task}/fail`: registra um código de erro sem conteúdo privado; o Laravel agenda retries limitados.

O worker mantém o áudio em um diretório temporário privado no Fedora, processa o arquivo original sem remover silêncio e apaga a cópia local ao sair do bloco de processamento. A gravação original do Vexa nunca é atualizada nem excluída.

## Variáveis

Laravel:

- `VEXA_TRANSCRIPTION_WORKER_SECRET`
- `VEXA_TRANSCRIPTION_SIGNATURE_TOLERANCE` (segundos)
- `VEXA_TRANSCRIPTION_LEASE_SECONDS` (lease renovado por heartbeat)
- `VEXA_TRANSCRIPTION_MAX_ATTEMPTS`
- `VEXA_TRANSCRIPTION_MIN_WORDS`
- `VEXA_TRANSCRIPTION_MIN_SPEECH_SECONDS`
- `VEXA_RETENTION_DAYS` (prazo configurável para apagar transcrições, segmentos, resumos, identificadores locais e eventos correlatos do Laravel. A rotina `meetings:purge-expired-data` não chama a API de exclusão do Vexa; gravações originais permanecem no Vexa. Registros legados sem `expires_at` ficam preservados.)

Fedora:

- `LARAVEL_BASE_URL` (HTTPS), `VEXA_BASE_URL` (HTTPS), `VEXA_TRANSCRIPTION_WORKER_SECRET`, `VEXA_API_KEY`
- `VEXA_TRANSCRIPTION_LEASE_SECONDS`, `WORKER_HTTP_TIMEOUT_SECONDS`, `WORKER_IDLE_SECONDS`
- `WORKER_TASK_TIMEOUT_SECONDS` (prazo máximo configurável de uma tarefa offline), `WORKER_TEMP_TTL_HOURS` (limpeza de temporários órfãos)
- `WHISPER_MODEL`, `WHISPER_COMPUTE_TYPE`, `WHISPER_BEAM_SIZE`, `VEXA_MAX_AUDIO_BYTES`

## Testes locais

Execute `python3 -m unittest discover -s worker/fedora -p 'test_*.py'`. Os testes mockam HTTP e o modelo; não acessam Vexa nem transmitem áudio.
