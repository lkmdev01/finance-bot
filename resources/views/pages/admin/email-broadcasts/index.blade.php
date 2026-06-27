@php
    $audienceOptions = [
        'marketing_opt_in' => 'Usuarios que aceitaram marketing',
        'verified_email' => 'Usuarios com e-mail verificado',
        'paid_active' => 'Clientes pagantes ativos',
        'whatsapp_verified' => 'Usuarios com WhatsApp ativado',
        'selected' => 'Selecionar usuarios manualmente',
        'manual' => 'Enviar para um e-mail especifico',
    ];
@endphp

<x-layouts.app>
    <x-slot name="title">Disparos de e-mail</x-slot>

    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-medium uppercase tracking-[0.22em] text-emerald-300">Admin</p>
                <h1 class="mt-2 text-3xl font-black text-white">Disparos de e-mail</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                    Envie comunicados com o template oficial do InovaFinance. Use para avisos operacionais, novidades e campanhas com consentimento.
                </p>
            </div>
            <a href="{{ route('admin.email-logs.index') }}" class="inline-flex items-center justify-center rounded-2xl border border-white/10 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-white/10">
                Ver historico
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
                <p class="text-sm text-slate-400">Usuarios com e-mail</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['users'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Opt-in marketing</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['marketing_opt_in'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Pagantes ativos</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['paid_active'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Enviados hoje</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['sent_today'] }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <form method="POST" action="{{ route('admin.email-broadcasts.store') }}" class="rounded-3xl border border-white/10 bg-slate-950/70 p-5 shadow-2xl shadow-black/20">
                @csrf

                <div class="grid gap-5">
                    <div>
                        <label for="audience" class="text-sm font-semibold text-white">Publico</label>
                        <select id="audience" name="audience" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-emerald-400">
                            @foreach ($audienceOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('audience', $previewAudience) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-xs text-slate-400">Para campanhas, prefira usuarios com opt-in de marketing. Para comunicados operacionais, use criterios mais restritos.</p>
                    </div>

                    <div>
                        <label for="manual_email" class="text-sm font-semibold text-white">E-mail manual</label>
                        <input id="manual_email" name="manual_email" value="{{ old('manual_email') }}" placeholder="cliente@email.com" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <label class="text-sm font-semibold text-white">Selecionar usuarios</label>
                            <span class="text-xs text-slate-400">Lista limitada aos 250 mais recentes</span>
                        </div>
                        <div class="mt-2 max-h-64 overflow-y-auto rounded-2xl border border-white/10 bg-slate-900/60 p-3">
                            @forelse ($users as $user)
                                <label class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm text-slate-200 hover:bg-white/5">
                                    <input type="checkbox" name="users[]" value="{{ $user->id }}" @checked(in_array($user->id, old('users', []))) class="rounded border-white/20 bg-slate-950 text-emerald-400">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold text-white">{{ $user->name }}</span>
                                        <span class="block truncate text-xs text-slate-400">{{ $user->email }} - {{ $user->billing_plan_code ?: 'sem plano' }}</span>
                                    </span>
                                </label>
                            @empty
                                <p class="px-3 py-4 text-sm text-slate-400">Nenhum usuario encontrado.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="subject" class="text-sm font-semibold text-white">Assunto</label>
                            <input id="subject" name="subject" value="{{ old('subject') }}" maxlength="120" placeholder="Novidade no InovaFinance" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">
                        </div>
                        <div>
                            <label for="preheader" class="text-sm font-semibold text-white">Preheader</label>
                            <input id="preheader" name="preheader" value="{{ old('preheader') }}" maxlength="180" placeholder="Resumo curto que aparece na caixa de entrada" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">
                        </div>
                    </div>

                    <div>
                        <label for="headline" class="text-sm font-semibold text-white">Titulo do e-mail</label>
                        <input id="headline" name="headline" value="{{ old('headline') }}" maxlength="140" placeholder="Oi @{{primeiro_nome}}, temos uma novidade para voce" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">
                    </div>

                    <div>
                        <label for="body" class="text-sm font-semibold text-white">Mensagem</label>
                        <textarea id="body" name="body" rows="10" maxlength="5000" placeholder="Escreva a mensagem principal..." class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm leading-6 text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">{{ old('body') }}</textarea>
                        <div class="mt-3 rounded-2xl border border-cyan-300/15 bg-cyan-400/10 p-3 text-xs leading-5 text-cyan-50">
                            <p class="font-bold">Variaveis disponiveis:</p>
                            <p class="mt-1 break-words font-mono text-cyan-100">@{{nome}}, @{{primeiro_nome}}, @{{email}}, @{{plano}}, @{{status_plano}}, @{{data_acesso}}, @{{link_dashboard}}, @{{link_suporte}}</p>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="cta_label" class="text-sm font-semibold text-white">Botao opcional</label>
                            <input id="cta_label" name="cta_label" value="{{ old('cta_label') }}" maxlength="40" placeholder="Acessar agora" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">
                        </div>
                        <div>
                            <label for="cta_url" class="text-sm font-semibold text-white">Link do botao</label>
                            <input id="cta_url" name="cta_url" value="{{ old('cta_url') }}" placeholder="{{ route('dashboard') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">
                        </div>
                    </div>

                    <label class="flex items-start gap-3 rounded-2xl border border-amber-300/20 bg-amber-400/10 p-4 text-sm text-amber-50">
                        <input type="checkbox" name="confirm_compliance" value="1" @checked(old('confirm_compliance')) class="mt-1 rounded border-amber-200/30 bg-slate-950 text-amber-300">
                        <span>Confirmo que os destinatarios autorizaram receber este tipo de comunicacao e que a mensagem respeita a politica do produto.</span>
                    </label>

                    <button type="submit" class="rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-black text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-300">
                        Enviar e-mail agora
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
                                <p class="truncate text-sm font-bold text-white">{{ $recipient->name ?: 'Contato manual' }}</p>
                                <p class="mt-1 truncate text-xs text-slate-400">{{ $recipient->email }}</p>
                            </div>
                        @empty
                            <p class="rounded-2xl border border-white/10 bg-slate-950/70 p-4 text-sm text-slate-400">Nenhum destinatario encontrado para este publico.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                    <h2 class="text-lg font-bold text-white">Ultimos disparos</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($recentEmails as $email)
                            <div class="rounded-2xl border border-white/10 bg-slate-950/70 p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="truncate text-sm font-bold text-white">{{ $email->user?->name ?: $email->to_email }}</p>
                                    <span class="rounded-full px-2 py-1 text-[11px] font-bold {{ $email->status === 'sent' ? 'bg-emerald-400/15 text-emerald-200' : 'bg-rose-400/15 text-rose-200' }}">{{ $email->status }}</span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs text-slate-400">{{ $email->subject }}</p>
                                <p class="mt-2 text-[11px] text-slate-500">{{ $email->created_at?->format('d/m/Y H:i') }}</p>
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
