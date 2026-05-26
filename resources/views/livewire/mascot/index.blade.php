<?php

use App\Services\MascotScoreService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public function with(): array
    {
        return [
            'mascot' => app(MascotScoreService::class)->sync(Auth::user()),
        ];
    }

    public function missions(array $mascot): array
    {
        $stats = $mascot['stats'] ?? [];

        $missions = [];

        if (empty($stats['has_bank_account'])) {
            $missions[] = [
                'title' => 'Cadastrar uma conta',
                'description' => 'Para separar saldo e organizar entradas/saidas por fonte.',
                'tone' => 'sky',
                'cta' => route('bank-accounts.create'),
                'cta_label' => 'Cadastrar conta',
                'whatsapp_text' => 'Quero cadastrar uma conta. Pode me ajudar?',
            ];
        }

        if (empty($stats['has_credit_card'])) {
            $missions[] = [
                'title' => 'Cadastrar um cartao',
                'description' => 'Para controlar limite, fatura e compras no credito.',
                'tone' => 'violet',
                'cta' => route('credit-cards.create'),
                'cta_label' => 'Cadastrar cartao',
                'whatsapp_text' => 'Registrar cartao de credito Nubank limite de 5000',
            ];
        }

        if (($stats['active_budgets'] ?? 0) === 0 && ($stats['transaction_count'] ?? 0) > 0) {
            $missions[] = [
                'title' => 'Criar 1 orcamento do mes',
                'description' => 'Orcamentos melhoram previsibilidade e ajudam nos alertas.',
                'tone' => 'amber',
                'cta' => route('budgets.create'),
                'cta_label' => 'Criar orcamento',
                'whatsapp_text' => 'Criar orcamento de 800 para alimentacao',
            ];
        }

        if (($stats['active_goals'] ?? 0) === 0) {
            $missions[] = [
                'title' => 'Criar uma meta',
                'description' => 'Uma meta ativa transforma economia em progresso visivel.',
                'tone' => 'emerald',
                'cta' => route('savings-goals.create'),
                'cta_label' => 'Criar meta',
                'whatsapp_text' => 'Definir meta viagem 5000',
            ];
        }

        if (($stats['categorized_ratio'] ?? 0) < 0.8 && ($stats['transaction_count'] ?? 0) > 0) {
            $missions[] = [
                'title' => 'Melhorar a categorizacao',
                'description' => 'Quanto mais categorias consistentes, melhores os insights.',
                'tone' => 'sky',
                'cta' => route('transactions.index'),
                'cta_label' => 'Ver transacoes',
                'whatsapp_text' => 'Quais foram meus gastos por categoria este mes?',
            ];
        }

        if (($stats['current_month_income'] ?? 0) > 0 && ($stats['current_month_expenses'] ?? 0) > ($stats['current_month_income'] ?? 0)) {
            $missions[] = [
                'title' => 'Revisar despesas do mes',
                'description' => 'Despesas acima da receita pedem ajuste rapido para evitar bola de neve.',
                'tone' => 'rose',
                'cta' => route('reports.index'),
                'cta_label' => 'Abrir relatorios',
                'whatsapp_text' => 'Quais foram meus gastos deste mes?',
            ];
        }

        if (empty($stats['has_subscription'])) {
            $missions[] = [
                'title' => 'Cadastrar assinaturas',
                'description' => 'Assim o sistema acompanha vencimentos automaticamente.',
                'tone' => 'amber',
                'cta' => route('subscriptions.create'),
                'cta_label' => 'Cadastrar assinatura',
                'whatsapp_text' => 'Criar assinatura Netflix mensal, dia 10, 19 reais no cartao Nubank',
            ];
        }

        return array_slice($missions, 0, 4);
    }

    public function whatsAppQuickActionUrl(string $message): ?string
    {
        $contactNumber = (string) config('whatsapp.tutorial.contact_number');
        $digits = preg_replace('/\\D+/', '', $contactNumber) ?? '';
        $digits = trim((string) $digits);

        if ($digits === '' || trim($message) === '') {
            return null;
        }

        return 'https://wa.me/'.$digits.'?text='.urlencode($message);
    }

    public function upcomingEvents(int $days = 30): array
    {
        $user = Auth::user();
        $today = now()->startOfDay();
        $until = now()->addDays($days)->endOfDay();

        $items = [];

        $subscriptions = $user->subscriptions()
            ->where('is_active', true)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $until->toDateString())
            ->orderBy('next_due_date')
            ->limit(10)
            ->get(['id', 'name', 'amount', 'next_due_date']);

        foreach ($subscriptions as $sub) {
            $date = $sub->next_due_date?->copy()->startOfDay();
            if (! $date) {
                continue;
            }

            $items[] = [
                'kind' => 'subscription',
                'id' => $sub->id,
                'label' => $sub->name,
                'amount' => (float) $sub->amount,
                'date' => $date,
                'is_overdue' => $date->lt($today),
            ];
        }

        $cards = $user->creditCards()
            ->where('is_active', true)
            ->whereNotNull('due_day')
            ->orderBy('name')
            ->get(['id', 'name', 'due_day']);

        foreach ($cards as $card) {
            $dueDay = (int) $card->due_day;
            if ($dueDay <= 0) {
                continue;
            }

            $base = \Carbon\Carbon::create(now()->year, now()->month, 1)->startOfMonth();
            $dueDate = $base->copy()->day(min($dueDay, $base->daysInMonth))->startOfDay();
            if ($dueDate->lt($today)) {
                $next = $base->copy()->addMonth();
                $dueDate = $next->day(min($dueDay, $next->daysInMonth))->startOfDay();
            }

            if ($dueDate->gt($until)) {
                continue;
            }

            $items[] = [
                'kind' => 'credit_card',
                'id' => $card->id,
                'label' => $card->name,
                'amount' => null,
                'date' => $dueDate,
                'is_overdue' => false,
            ];
        }

        usort($items, fn ($a, $b) => ($a['date'] ?? $today) <=> ($b['date'] ?? $today));

        return array_slice($items, 0, 6);
    }

    public function mascotName(): string
    {
        return (string) config('mascot.name', 'Orbita');
    }

    public function mascotEmoji(): string
    {
        return html_entity_decode((string) config('mascot.emoji', '&#128640;'), ENT_QUOTES, 'UTF-8');
    }

    public function toneClasses(string $tone): array
    {
        return match ($tone) {
            'emerald' => [
                'badge' => 'border-emerald-400/20 bg-emerald-400/15 text-emerald-200',
                'panel' => 'from-emerald-500/20 via-emerald-400/10 to-transparent border-emerald-400/20',
                'glow' => 'shadow-[0_24px_80px_rgba(16,185,129,0.22)]',
                'icon' => 'bg-emerald-400/15 text-emerald-200',
            ],
            'amber' => [
                'badge' => 'border-amber-400/20 bg-amber-400/15 text-amber-100',
                'panel' => 'from-amber-400/25 via-orange-400/10 to-transparent border-amber-300/20',
                'glow' => 'shadow-[0_24px_80px_rgba(245,158,11,0.22)]',
                'icon' => 'bg-amber-400/15 text-amber-100',
            ],
            'rose' => [
                'badge' => 'border-rose-400/20 bg-rose-400/15 text-rose-100',
                'panel' => 'from-rose-400/20 via-pink-400/10 to-transparent border-rose-300/20',
                'glow' => 'shadow-[0_24px_80px_rgba(244,63,94,0.2)]',
                'icon' => 'bg-rose-400/15 text-rose-100',
            ],
            'violet' => [
                'badge' => 'border-violet-400/20 bg-violet-400/15 text-violet-100',
                'panel' => 'from-violet-400/20 via-fuchsia-400/10 to-transparent border-violet-300/20',
                'glow' => 'shadow-[0_24px_80px_rgba(139,92,246,0.22)]',
                'icon' => 'bg-violet-400/15 text-violet-100',
            ],
            default => [
                'badge' => 'border-sky-400/20 bg-sky-400/15 text-sky-100',
                'panel' => 'from-sky-400/20 via-cyan-400/10 to-transparent border-sky-300/20',
                'glow' => 'shadow-[0_24px_80px_rgba(14,165,233,0.2)]',
                'icon' => 'bg-sky-400/15 text-sky-100',
            ],
        };
    }

    public function statLabel(string $key): string
    {
        return match ($key) {
            'consistency' => 'Consistência',
            'balance' => 'Equilíbrio',
            'budget' => 'Orçamento',
            'savings' => 'Economia',
            default => ucfirst($key),
        };
    }

    public function achievementIcon(string $icon): string
    {
        return match ($icon) {
            'seedling' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20v-8"/><path d="M8 20h8"/><path d="M12 12c0-4 2-7 6-8 0 4-2 7-6 8Z"/><path d="M12 14c0-3-2-5-5-6 0 3 2 5 5 6Z"/></svg>',
            'flame' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3c1.5 2 2.5 3.8 2.5 6.2A3.5 3.5 0 0 1 11 12.7c0-1.8.6-3.2 1-4.4-2.8 1.4-5 4-5 7.1A5 5 0 0 0 12 20a5 5 0 0 0 5-4.6c0-2.4-1.2-4.6-3.6-6.7"/></svg>',
            'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l7 3v5c0 4.2-2.4 7.8-7 10-4.6-2.2-7-5.8-7-10V6l7-3Z"/><path d="m9.5 12.5 1.8 1.8 3.7-4"/></svg>',
            'banknotes' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 12h.01M17 12h.01"/><circle cx="12" cy="12" r="2.5"/></svg>',
            'chart-bar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20v-4"/></svg>',
            'wallet' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5.5A2.5 2.5 0 0 1 3 16.5v-9Z"/><path d="M16 13h4"/><path d="M3 8h14"/></svg>',
            default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 17l-5 3 1.5-5.7L4 10.5l5.8-.4L12 4l2.2 6.1 5.8.4-4.5 3.8L17 20l-5-3Z"/></svg>',
        };
    }
}; ?>

<div class="max-w-full space-y-6 overflow-x-hidden p-4 sm:space-y-8 sm:p-6">
    @php
        $moodClasses = $this->toneClasses($mascot['mood']['tone']);
        $recentClasses = $this->toneClasses($mascot['recent_achievement']['tone'] ?? 'amber');
    @endphp

    <section class="w-full max-w-full overflow-hidden rounded-[1.75rem] border border-white/10 bg-[radial-gradient(circle_at_top_left,rgba(251,191,36,0.18),transparent_35%),radial-gradient(circle_at_bottom_right,rgba(14,165,233,0.14),transparent_28%),linear-gradient(135deg,#020617_0%,#0f172a_45%,#111827_100%)] p-4 sm:p-6 lg:p-8 lg:rounded-[2rem]">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:gap-8">
            <div class="min-w-0 space-y-6">
                <div class="inline-flex items-center gap-2 rounded-full border border-amber-300/20 bg-amber-300/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-amber-100">
                    {{ $this->mascotName() }}
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-300"></span>
                    Sistema de Pontuação
                </div>

                <div class="space-y-3">
                    <h1 class="max-w-3xl break-words text-3xl font-black tracking-tight text-white sm:text-4xl md:text-5xl">
                        Conheça {{ $this->mascotName() }}
                    </h1>
                    <p class="max-w-3xl break-words text-base leading-7 text-slate-300 sm:text-lg sm:leading-8">
                        {{ config('mascot.companion_copy', 'Seu foguete virtual que celebra suas conquistas financeiras.') }} {{ $this->mascotName() }} acompanha seus hábitos, reage ao seu momento e te ajuda a manter constância com medalhas, XP e foco claro.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <article class="min-w-0 rounded-[1.5rem] border border-white/10 bg-white/5 p-4 backdrop-blur sm:rounded-3xl sm:p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Pontuação</p>
                                <p class="mt-3 text-4xl font-black text-white">{{ $mascot['score'] }}</p>
                            </div>
                            <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-300/15 text-3xl">
                                {{ $this->mascotEmoji() }}
                            </div>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-slate-300">{{ $mascot['mood']['headline'] }}</p>
                    </article>

                    <article class="min-w-0 rounded-[1.5rem] border border-white/10 bg-white/5 p-4 backdrop-blur sm:rounded-3xl sm:p-5">
                        <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Nível e XP</p>
                        <div class="mt-3 flex items-end gap-3">
                            <p class="text-4xl font-black text-white">{{ $mascot['level'] }}</p>
                            <p class="pb-1 text-sm text-slate-400">{{ number_format($mascot['xp']) }} XP</p>
                        </div>
                        <div class="mt-4 h-3 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full bg-gradient-to-r from-amber-300 via-orange-400 to-rose-400" style="width: {{ $mascot['level_progress'] }}%"></div>
                        </div>
                        <p class="mt-3 text-xs uppercase tracking-[0.16em] text-slate-400">
                            {{ number_format($mascot['xp_in_level']) }} / {{ number_format($mascot['xp_for_next_level']) }} XP neste nível
                        </p>
                    </article>

                    <article class="min-w-0 rounded-[1.5rem] border border-white/10 bg-white/5 p-4 backdrop-blur sm:rounded-3xl sm:p-5">
                        <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Sequência atual</p>
                        <div class="mt-3 flex items-end gap-3">
                            <p class="text-4xl font-black text-white">{{ $mascot['current_streak'] }}</p>
                            <p class="pb-1 text-sm text-slate-400">dias seguidos</p>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-slate-300">
                            Melhor marca: {{ $mascot['best_streak'] }} dias. Cada novo registro reforça o humor do {{ $this->mascotName() }}.
                        </p>
                    </article>
                </div>
            </div>

            <div class="min-w-0 grid gap-4 md:grid-cols-2 xl:grid-cols-1">
                <article class="min-w-0 rounded-[1.5rem] border bg-gradient-to-br {{ $moodClasses['panel'] }} p-5 sm:rounded-[2rem] sm:p-6 {{ $moodClasses['glow'] }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] {{ $moodClasses['badge'] }}">
                                {{ $mascot['mood']['label'] }}
                            </span>
                            <h2 class="mt-4 text-2xl font-black text-white">{{ $mascot['mood']['headline'] }}</h2>
                            <p class="mt-3 max-w-md text-sm leading-7 text-slate-200">{{ $mascot['mood']['message'] }}</p>
                        </div>
                        <div class="inline-flex h-20 w-20 items-center justify-center rounded-[1.75rem] border border-white/10 bg-white/10 text-5xl">
                            {{ $this->mascotEmoji() }}
                        </div>
                    </div>
                </article>

                <article class="min-w-0 rounded-[1.5rem] border bg-gradient-to-br {{ $recentClasses['panel'] }} p-5 sm:rounded-[2rem] sm:p-6 {{ $recentClasses['glow'] }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] {{ $recentClasses['badge'] }}">
                                {{ $mascot['recent_achievement'] ? 'Conquista desbloqueada' : 'Próximo foco' }}
                            </span>
                            @if($mascot['recent_achievement'])
                                <h2 class="mt-4 text-2xl font-black text-white">{{ $mascot['recent_achievement']['title'] }}</h2>
                                <p class="mt-3 text-sm leading-7 text-slate-200">{{ $mascot['recent_achievement']['description'] }}</p>
                            @else
                                <h2 class="mt-4 text-2xl font-black text-white">{{ $mascot['focus_area']['title'] }}</h2>
                                <p class="mt-3 text-sm leading-7 text-slate-200">{{ $mascot['focus_area']['description'] }}</p>
                            @endif
                        </div>
                        <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl {{ $recentClasses['icon'] }}">
                            <span class="h-8 w-8">{!! $this->achievementIcon($mascot['recent_achievement']['icon'] ?? 'sparkles') !!}</span>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-[1.5rem] border border-white/10 bg-slate-950/80 p-5 sm:rounded-[2rem] sm:p-6 shadow-[0_18px_70px_rgba(2,6,23,0.32)]">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Componentes do score</p>
                    <h2 class="mt-2 text-2xl font-black text-white">O que mais pesa na Pontuação do {{ $this->mascotName() }}</h2>
                </div>
                <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-200">
                    {{ $mascot['badges_unlocked'] }} medalhas
                </span>
            </div>

            <div class="mt-8 space-y-5">
                @foreach($mascot['score_breakdown'] as $key => $value)
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <p class="text-sm font-semibold text-slate-200">{{ $this->statLabel($key) }}</p>
                            <p class="text-sm text-slate-400">{{ $value }}/25</p>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-white/10">
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-sky-400 via-cyan-300 to-emerald-300"
                                style="width: {{ min(100, round(($value / 25) * 100)) }}%"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-[1.5rem] border border-white/10 bg-slate-950/80 p-5 sm:rounded-[2rem] sm:p-6 shadow-[0_18px_70px_rgba(2,6,23,0.32)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Nivel e progresso</p>
                    <h2 class="mt-2 text-2xl font-black text-white">Nivel {{ $mascot['level'] }} do {{ $this->mascotName() }}</h2>
                    <p class="mt-3 text-sm leading-7 text-slate-300">
                        {{ $mascot['xp_in_level'] }}/{{ $mascot['xp_for_next_level'] }} XP neste nivel
                        <span class="mx-1">•</span>
                        {{ $mascot['level_progress'] }}% para o proximo
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Sequencia</p>
                    <p class="mt-1 text-2xl font-black text-white">{{ $mascot['current_streak'] }}d</p>
                    <p class="text-xs text-slate-400">melhor: {{ $mascot['best_streak'] }}d</p>
                </div>
            </div>

            <div class="mt-5 h-3 overflow-hidden rounded-full bg-white/10">
                <div
                    class="h-full rounded-full bg-gradient-to-r from-sky-400 via-cyan-300 to-emerald-300"
                    style="width: {{ min(100, (int) ($mascot['level_progress'] ?? 0)) }}%"
                ></div>
            </div>

            <div class="mt-8 grid gap-3">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Receitas do mes</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-300">R$ {{ number_format($mascot['stats']['current_month_income'], 2, ',', '.') }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Despesas do mes</p>
                    <p class="mt-2 text-2xl font-bold text-rose-300">R$ {{ number_format($mascot['stats']['current_month_expenses'], 2, ',', '.') }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Economia do mes</p>
                    <p class="mt-2 text-2xl font-bold text-amber-200">R$ {{ number_format($mascot['stats']['current_month_savings'], 2, ',', '.') }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-[1.05fr_0.95fr]">
        <div class="rounded-[1.5rem] border border-white/10 bg-slate-950/80 p-5 sm:rounded-[2rem] sm:p-6 shadow-[0_18px_70px_rgba(2,6,23,0.32)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Missao do momento</p>
                    <h2 class="mt-2 text-2xl font-black text-white">{{ $mascot['focus_area']['title'] }}</h2>
                    <p class="mt-3 text-sm leading-7 text-slate-300">{{ $mascot['focus_area']['description'] }}</p>
                </div>
                <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-200">
                    {{ $mascot['focus_area']['score'] }}/25
                </span>
            </div>

                @php $missions = $this->missions($mascot); @endphp
                @if($missions !== [])
                    <div class="mt-8 grid gap-3">
                        @foreach($missions as $mission)
                            @php $missionClasses = $this->toneClasses($mission['tone'] ?? 'sky'); @endphp
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-bold text-white">{{ $mission['title'] }}</p>
                                        <p class="mt-1 text-sm leading-7 text-slate-300">{{ $mission['description'] }}</p>
                                    </div>
                                    @php
                                        $whatsAppUrl = !empty($mission['whatsapp_text'])
                                            ? $this->whatsAppQuickActionUrl((string) $mission['whatsapp_text'])
                                            : null;
                                    @endphp

                                    <div class="shrink-0 flex flex-col items-end gap-2">
                                        @if($whatsAppUrl)
                                            <a href="{{ $whatsAppUrl }}" target="_blank" rel="noopener noreferrer" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-bold uppercase tracking-[0.18em] text-slate-200 hover:bg-white/10">
                                                Abrir WhatsApp
                                            </a>
                                        @endif
                                        <a href="{{ $mission['cta'] }}" wire:navigate class="text-xs font-semibold text-slate-300 underline underline-offset-4 hover:text-white">
                                            {{ $mission['cta_label'] }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
        </div>

        <div class="rounded-[1.5rem] border border-white/10 bg-slate-950/80 p-5 sm:rounded-[2rem] sm:p-6 shadow-[0_18px_70px_rgba(2,6,23,0.32)]">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Radar</p>
                    <h2 class="mt-2 text-2xl font-black text-white">O que vem pela frente</h2>
                    <p class="mt-2 text-sm leading-7 text-slate-300">Vencimentos e lembretes para voce nao ser pego de surpresa.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('subscriptions.index') }}" wire:navigate class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-bold uppercase tracking-[0.18em] text-slate-200 hover:bg-white/10">Assinaturas</a>
                    <a href="{{ route('credit-cards.index') }}" wire:navigate class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-bold uppercase tracking-[0.18em] text-slate-200 hover:bg-white/10">Cartoes</a>
                </div>
            </div>

            @php $events = $this->upcomingEvents(30); @endphp
            @if($events !== [])
                <div class="mt-8 space-y-3">
                    @foreach($events as $event)
                        @php
                            $daysTo = now()->startOfDay()->diffInDays($event['date'], false);
                            $isOverdue = (bool) ($event['is_overdue'] ?? false);
                            $isCard = ($event['kind'] ?? '') === 'credit_card';
                            $href = $isCard
                                ? (isset($event['id']) ? route('credit-cards.edit', $event['id']) : route('credit-cards.index'))
                                : (isset($event['id']) ? route('subscriptions.edit', $event['id']) : route('subscriptions.index'));
                        @endphp
                        <a href="{{ $href }}" wire:navigate class="block rounded-2xl border border-white/10 bg-white/5 p-4 hover:bg-white/10 transition">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-white truncate">
                                        {{ $isCard ? 'Fatura: ' : '' }}{{ $event['label'] }}
                                    </p>
                                    <p class="mt-1 text-xs uppercase tracking-[0.16em] text-slate-400">
                                        {{ $isCard ? 'Cartao' : 'Assinatura' }} <span class="mx-1">•</span> {{ $event['date']->format('d/m') }}
                                    </p>
                                </div>
                                <span class="shrink-0 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-200">
                                    {{ $isOverdue ? 'Atrasado' : 'Em '.max(0, $daysTo).'d' }}
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="mt-8 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-sm font-semibold text-white">Sem vencimentos nos proximos 30 dias.</p>
                    <p class="mt-1 text-sm leading-7 text-slate-300">Se quiser, cadastre uma assinatura para eu acompanhar automaticamente.</p>
                    <a href="{{ route('subscriptions.create') }}" wire:navigate class="mt-4 inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-bold uppercase tracking-[0.18em] text-slate-200 hover:bg-white/10">
                        Cadastrar assinatura
                    </a>
                </div>
            @endif
        </div>
    </section>

    <section class="rounded-[1.5rem] border border-white/10 bg-slate-950/80 p-5 sm:rounded-[2rem] sm:p-6 shadow-[0_18px_70px_rgba(2,6,23,0.32)]">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Conquistas e medalhas</p>
                <h2 class="mt-2 text-2xl font-black text-white">{{ $this->mascotName() }} celebra cada marco importante</h2>
            </div>
            <p class="max-w-xl text-sm leading-7 text-slate-300">
                Ganhe medalhas por sequências de economia, manter-se dentro do Orçamento e alcançar metas. As desbloqueadas ficam registradas no seu histórico.
            </p>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($mascot['achievements'] as $achievement)
                @php
                    $classes = $this->toneClasses($achievement['tone']);
                @endphp
                <article class="rounded-[1.75rem] border p-5 transition {{ $achievement['is_unlocked'] ? 'border-white/10 bg-white/5' : 'border-white/5 bg-slate-950/40 opacity-70' }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl {{ $classes['icon'] }}">
                            <span class="h-7 w-7">{!! $this->achievementIcon($achievement['icon']) !!}</span>
                        </div>
                        <span class="rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] {{ $achievement['is_unlocked'] ? $classes['badge'] : 'border-white/10 bg-white/5 text-slate-400' }}">
                            {{ $achievement['is_unlocked'] ? 'Desbloqueada' : 'Em progresso' }}
                        </span>
                    </div>

                    <h3 class="mt-5 text-xl font-bold text-white">{{ $achievement['title'] }}</h3>
                    <p class="mt-3 text-sm leading-7 text-slate-300">{{ $achievement['description'] }}</p>

                    @if($achievement['is_unlocked'] && $achievement['unlocked_at'])
                        <p class="mt-4 text-xs uppercase tracking-[0.16em] text-slate-500">
                            Liberada {{ $achievement['unlocked_at']->diffForHumans() }}
                        </p>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
</div>


