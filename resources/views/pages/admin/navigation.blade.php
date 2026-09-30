<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ([
        ['admin.beta.index', 'Usuários e funil'],
        ['admin.commercial-readiness', 'Operação comercial'],
        ['assistant.observability', 'Observabilidade IA'],
        ['monitoring.index', 'Monitoramento'],
        ['admin.whatsapp-broadcasts.index', 'Disparos WhatsApp'],
        ['admin.email-broadcasts.index', 'Disparos de e-mail'],
        ['admin.email-logs.index', 'Histórico de e-mails'],
        ['assistant.operations.settings', 'Configurações do assistente'],
    ] as [$destination, $label])
        <a href="{{ route($destination) }}" wire:navigate class="rounded-2xl border border-white/10 bg-white/5 p-5 font-semibold transition hover:bg-white/10">{{ $label }}</a>
    @endforeach
</div>
