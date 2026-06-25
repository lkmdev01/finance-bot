@php
    $filterOptions = [
        'all' => 'Todos',
        'beta' => 'Em beta',
        'active_paid' => 'Pagantes ativos',
        'payment_issue' => 'Atencao pagamento',
        'whatsapp_missing' => 'Sem WhatsApp',
    ];

    $statusClasses = [
        'candidate' => 'border-sky-300/20 bg-sky-400/10 text-sky-100',
        'invited' => 'border-amber-300/20 bg-amber-400/10 text-amber-100',
        'active' => 'border-emerald-300/20 bg-emerald-400/10 text-emerald-100',
        'watch' => 'border-cyan-300/20 bg-cyan-400/10 text-cyan-100',
        'paused' => 'border-slate-300/20 bg-slate-400/10 text-slate-100',
        'done' => 'border-violet-300/20 bg-violet-400/10 text-violet-100',
    ];

    $formatDate = fn ($date) => $date ? $date->format('d/m/Y H:i') : 'Nao informado';
@endphp

<x-layouts.app>
    <x-slot name="title">Beta</x-slot>

    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p class="text-sm font-medium uppercase tracking-[0.22em] text-emerald-300">Admin</p>
                <h1 class="mt-2 text-3xl font-black text-white sm:text-4xl">Painel Beta</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">
                    Acompanhe usuarios do beta, ativacao do WhatsApp, pagamento, uso recente, erros do assistente e observacoes internas antes de ampliar a venda.
                </p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <a href="{{ route('assistant.observability') }}" class="inline-flex items-center justify-center rounded-2xl border border-white/10 px-4 py-2 text-sm font-semibold text-slate-200 transition hover:bg-white/10">
                    Observabilidade IA
                </a>
                <a href="{{ route('admin.whatsapp-broadcasts.index') }}" class="inline-flex items-center justify-center rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-4 py-2 text-sm font-semibold text-emerald-100 transition hover:bg-emerald-400/20">
                    Disparos
                </a>
            </div>
        </div>

        @if (session('message'))
            <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm font-semibold text-emerald-100">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">Revise os dados:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Usuarios</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $summary['total_users'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Em beta</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $summary['beta_users'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">WhatsApp ativado</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $summary['whatsapp_verified'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Pagantes ativos</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $summary['active_paid'] }}</p>
            </div>
            <div class="rounded-3xl border border-rose-300/20 bg-rose-400/10 p-5">
                <p class="text-sm text-rose-100/80">Erros 7 dias</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $summary['errors_7d'] }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.beta.index') }}" class="rounded-3xl border border-white/10 bg-slate-950/70 p-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto]">
                <input
                    name="q"
                    value="{{ $search }}"
                    placeholder="Buscar por nome, email ou telefone"
                    class="w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400"
                >
                <select name="filter" class="w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-emerald-400">
                    @foreach ($filterOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filter === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-2xl bg-cyan-400 px-5 py-3 text-sm font-black text-slate-950 transition hover:bg-cyan-300">
                    Filtrar
                </button>
            </div>
        </form>

        <div class="space-y-4">
            @forelse ($users as $user)
                @php
                    $latestLog = $latestLogs->get($user->id);
                    $latestSubscription = $latestSubscriptions->get($user->id);
                    $recentErrors = (int) ($recentErrorCounts->get($user->id) ?? 0);
                    $betaLabel = $user->beta_status ? ($statuses[$user->beta_status] ?? $user->beta_status) : 'Sem status';
                    $paymentActive = $user->hasActivePaidPlan();
                @endphp

                <article class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950/70 shadow-2xl shadow-black/10">
                    <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                        <div class="min-w-0 space-y-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="truncate text-xl font-black text-white">{{ $user->name }}</h2>
                                        <span class="rounded-full border px-3 py-1 text-xs font-bold {{ $statusClasses[$user->beta_status] ?? 'border-white/10 bg-white/5 text-slate-200' }}">
                                            {{ $betaLabel }}
                                        </span>
                                        @if ($user->whatsapp_verified_at)
                                            <span class="rounded-full border border-emerald-300/20 bg-emerald-400/10 px-3 py-1 text-xs font-bold text-emerald-100">WhatsApp ok</span>
                                        @else
                                            <span class="rounded-full border border-amber-300/20 bg-amber-400/10 px-3 py-1 text-xs font-bold text-amber-100">WhatsApp pendente</span>
                                        @endif
                                        <span class="rounded-full border {{ $paymentActive ? 'border-emerald-300/20 bg-emerald-400/10 text-emerald-100' : 'border-white/10 bg-white/5 text-slate-200' }} px-3 py-1 text-xs font-bold">
                                            {{ $paymentActive ? 'Premium ativo' : 'Sem premium ativo' }}
                                        </span>
                                    </div>
                                    <p class="mt-2 break-all text-sm text-slate-400">{{ $user->email }}</p>
                                    <p class="mt-1 text-sm text-slate-400">{{ $user->phone_number ?: 'Telefone nao informado' }}</p>
                                </div>
                                <div class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm text-slate-300">
                                    <p class="font-bold text-white">Ultimo uso</p>
                                    <p class="mt-1">{{ $formatDate($latestLog?->created_at ?? $user->updated_at) }}</p>
                                    @if ($latestLog)
                                        <p class="mt-1 max-w-xs truncate text-xs text-slate-500">{{ $latestLog->message }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Pagamento</p>
                                    <p class="mt-2 text-sm font-bold text-white">{{ $user->billing_plan_status_label }}</p>
                                    <p class="mt-1 text-xs text-slate-400">Plano: {{ $user->billing_plan_code ?: 'starter' }}</p>
                                    <p class="mt-1 text-xs text-slate-400">Acesso ate: {{ $formatDate($user->billing_access_ends_at) }}</p>
                                </div>
                                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Assinatura</p>
                                    <p class="mt-2 text-sm font-bold text-white">{{ $latestSubscription?->status ?: 'Sem registro' }}</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ $latestSubscription?->plan_code ?: 'Sem plano' }}</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ $latestSubscription?->frequency ?: 'Sem ciclo' }}</p>
                                </div>
                                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Uso</p>
                                    <p class="mt-2 text-sm font-bold text-white">{{ $user->transactions_count }} transacoes</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ $user->drive_files_count }} arquivos no Drive</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ $user->notes_count }} notas</p>
                                </div>
                                <div class="rounded-2xl border {{ $recentErrors > 0 ? 'border-rose-300/20 bg-rose-400/10' : 'border-white/10 bg-white/[0.03]' }} p-4">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] {{ $recentErrors > 0 ? 'text-rose-100/80' : 'text-slate-500' }}">Erros recentes</p>
                                    <p class="mt-2 text-sm font-bold text-white">{{ $recentErrors }} em 7 dias</p>
                                    <p class="mt-1 text-xs {{ $recentErrors > 0 ? 'text-rose-100/70' : 'text-slate-400' }}">{{ $latestLog?->classification ?: 'Sem conversa recente' }}</p>
                                </div>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('admin.beta.users.update', $user) }}" class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                            @csrf
                            @method('PATCH')

                            <label class="text-sm font-bold text-white" for="beta_status_{{ $user->id }}">Status do beta</label>
                            <select id="beta_status_{{ $user->id }}" name="beta_status" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-emerald-400">
                                <option value="">Sem status</option>
                                @foreach ($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected($user->beta_status === $value)>{{ $label }}</option>
                                @endforeach
                            </select>

                            <label class="mt-4 block text-sm font-bold text-white" for="beta_notes_{{ $user->id }}">Observacoes</label>
                            <textarea id="beta_notes_{{ $user->id }}" name="beta_notes" rows="5" maxlength="5000" placeholder="Ex: testando Drive, reclamou de checkout, precisa retorno..." class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm leading-6 text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">{{ old("beta_notes_{$user->id}", $user->beta_notes) }}</textarea>

                            <div class="mt-4 flex flex-col gap-2 text-xs text-slate-400">
                                <span>Convite beta: {{ $formatDate($user->beta_invited_at) }}</span>
                                <span>Criado em: {{ $formatDate($user->created_at) }}</span>
                            </div>

                            <button type="submit" class="mt-4 w-full rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-black text-slate-950 transition hover:bg-emerald-300">
                                Salvar acompanhamento
                            </button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-8 text-center">
                    <p class="text-lg font-bold text-white">Nenhum usuario encontrado.</p>
                    <p class="mt-2 text-sm text-slate-400">Ajuste os filtros ou busque por outro nome, email ou telefone.</p>
                </div>
            @endforelse
        </div>

        <div>
            {{ $users->links() }}
        </div>
    </div>
</x-layouts.app>
