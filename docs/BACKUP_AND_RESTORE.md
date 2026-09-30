# Backup e restauracao

O banco contem dados financeiros e credenciais criptografadas. Um backup so e
considerado valido depois que uma restauracao completa foi testada.

## Configuracao no Coolify

1. Ative backups automaticos do banco MySQL para um destino externo ao servidor.
2. Execute ao menos um backup diario e retenha copias diarias por 30 dias.
3. Proteja o destino com credenciais exclusivas e acesso privado.
4. Mantenha `APP_KEY` em um cofre separado. Sem ela, os campos criptografados do
   backup nao podem ser recuperados.
5. Configure alerta de falha do job de backup no provedor ou no Coolify.

## Teste de restauracao

Trimestralmente, restaure o backup mais recente em um banco isolado e valide:

```bash
php artisan migrate:status
php artisan tinker --execute="dump(\App\Models\User::count(), \App\Models\Transaction::count());"
php artisan whatsapp:reliability
```

Nao conecte o banco restaurado ao Baileys, scheduler ou workers de producao. Isso
evita notificacoes e cobrancas duplicadas.

Depois do teste bem-sucedido, configure no ambiente de producao a data UTC:

```dotenv
BACKUP_LAST_RESTORE_TEST_AT=2026-09-30T12:00:00Z
```

Rode `php artisan optimize:clear` para que o painel de prontidao comercial passe
a usar o novo valor. Dumps locais (`*.dmp`) sao ignorados pelo Git e nunca devem
ser enviados ao repositorio.
