@php
    $audienceOptions = [
        'verified' => 'Usuarios com WhatsApp ativado',
        'active_30' => 'Contatos ativos nos ultimos 30 dias',
        'all' => 'Todos os contatos cadastrados',
        'selected' => 'Selecionar contatos manualmente',
        'manual' => 'Enviar para um numero especifico',
    ];
@endphp

<x-layouts.app>
    <x-slot name="title">Disparos WhatsApp</x-slot>

    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-medium uppercase tracking-[0.22em] text-emerald-300">Admin</p>
                <h1 class="mt-2 text-3xl font-black text-white">Disparos WhatsApp</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-300">
                    Envie comunicados, avisos operacionais e mensagens de marketing para numeros cadastrados. Use com cuidado: cada envio fica registrado para auditoria.
                </p>
            </div>
            <a href="{{ route('whatsapp.index') }}" class="inline-flex items-center justify-center rounded-2xl border border-white/10 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-white/10">
                Voltar ao WhatsApp
            </a>
        </div>

        @if (session('message'))
            <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm font-semibold text-emerald-100">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">Revise antes de enviar:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Contatos</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['contacts'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">WhatsApp ativado</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['verified'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Ativos 30 dias</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['active_30'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Enviados hoje</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['sent_today'] }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <form method="POST" action="{{ route('admin.whatsapp-broadcasts.store') }}" class="rounded-3xl border border-white/10 bg-slate-950/70 p-5 shadow-2xl shadow-black/20">
                @csrf

                <div class="grid gap-5">
                    <div>
                        <label for="audience" class="text-sm font-semibold text-white">Publico</label>
                        <select id="audience" name="audience" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-emerald-400">
                            @foreach ($audienceOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('audience', $previewAudience) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-xs text-slate-400">Para preview de outro publico, selecione e atualize a pagina com o filtro no topo depois. O envio usa o publico escolhido aqui.</p>
                    </div>

                    <div>
                        <label for="manual_phone" class="text-sm font-semibold text-white">Numero manual</label>
                        <input id="manual_phone" name="manual_phone" value="{{ old('manual_phone') }}" placeholder="5513999999999" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <label class="text-sm font-semibold text-white">Selecionar contatos</label>
                            <span class="text-xs text-slate-400">Lista limitada aos 250 mais recentes</span>
                        </div>
                        <div class="mt-2 max-h-64 overflow-y-auto rounded-2xl border border-white/10 bg-slate-900/60 p-3">
                            @forelse ($contacts as $contact)
                                <label class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm text-slate-200 hover:bg-white/5">
                                    <input type="checkbox" name="contacts[]" value="{{ $contact->id }}" @checked(in_array($contact->id, old('contacts', []))) class="rounded border-white/20 bg-slate-950 text-emerald-400">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold text-white">{{ $contact->user?->name ?: $contact->name ?: 'Contato WhatsApp' }}</span>
                                        <span class="block truncate text-xs text-slate-400">{{ $contact->phone_number }} - {{ $contact->updated_at?->diffForHumans() }}</span>
                                    </span>
                                </label>
                            @empty
                                <p class="px-3 py-4 text-sm text-slate-400">Nenhum contato encontrado.</p>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <label for="message" class="text-sm font-semibold text-white">Mensagem</label>
                        <textarea id="message" name="message" rows="9" maxlength="1200" placeholder="Ex: Oi, aqui e o InovaFinance. Temos uma novidade..." class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm leading-6 text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">{{ old('message') }}</textarea>
                        <p class="mt-2 text-xs text-slate-400">Evite promessas agressivas. Seja claro sobre o motivo do contato e use mensagens curtas.</p>
                    </div>

                    <label class="flex items-start gap-3 rounded-2xl border border-amber-300/20 bg-amber-400/10 p-4 text-sm text-amber-50">
                        <input type="checkbox" name="confirm_compliance" value="1" @checked(old('confirm_compliance')) class="mt-1 rounded border-amber-200/30 bg-slate-950 text-amber-300">
                        <span>Confirmo que os destinatarios autorizaram receber comunicados e que esta mensagem respeita o relacionamento com o usuario.</span>
                    </label>

                    <button type="submit" class="rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-black text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-300">
                        Enviar mensagem agora
                    </button>
                </div>
            </form>

            <aside class="space-y-6">
                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                    <h2 class="text-lg font-bold text-white">Preview do publico</h2>
                    <p class="mt-1 text-sm text-slate-400">Amostra para: {{ $audienceOptions[$previewAudience] ?? $previewAudience }}</p>
                    <div class="mt-4 space-y-3">
                        @forelse ($previewRecipients as $recipient)
                            <div class="rounded-2xl border border-white/10 bg-slate-950/70 p-3">
                                <p class="truncate text-sm font-bold text-white">{{ $recipient['name'] }}</p>
                                <p class="mt-1 text-xs text-slate-400">{{ $recipient['phone'] }}</p>
                            </div>
                        @empty
                            <p class="rounded-2xl border border-white/10 bg-slate-950/70 p-4 text-sm text-slate-400">Nenhum destinatario encontrado para este publico.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                    <h2 class="text-lg font-bold text-white">Ultimos disparos</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($recentBroadcasts as $broadcast)
                            <div class="rounded-2xl border border-white/10 bg-slate-950/70 p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="truncate text-sm font-bold text-white">{{ $broadcast->recipient_name ?: $broadcast->phone_number }}</p>
                                    <span class="rounded-full px-2 py-1 text-[11px] font-bold {{ $broadcast->status === 'sent' ? 'bg-emerald-400/15 text-emerald-200' : 'bg-rose-400/15 text-rose-200' }}">{{ $broadcast->status }}</span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs text-slate-400">{{ $broadcast->message }}</p>
                                <p class="mt-2 text-[11px] text-slate-500">{{ $broadcast->created_at?->format('d/m/Y H:i') }} por {{ $broadcast->admin?->name ?? 'admin' }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">Nenhum disparo registrado ainda.</p>
                        @endforelse
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.app>
