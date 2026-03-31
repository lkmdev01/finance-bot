<?php

use App\Services\FinancialScoreService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public function with(): array
    {
        return [
            'finny' => app(FinancialScoreService::class)->sync(Auth::user()),
        ];
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
            'consistency' => 'Consistencia',
            'balance' => 'Equilibrio',
            'budget' => 'Orcamento',
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

<div class="space-y-8 p-6">
    @php
        $moodClasses = $this->toneClasses($finny['mood']['tone']);
        $recentClasses = $this->toneClasses($finny['recent_achievement']['tone'] ?? 'amber');
    @endphp

    <section class="overflow-hidden rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top_left,rgba(251,191,36,0.18),transparent_35%),radial-gradient(circle_at_bottom_right,rgba(14,165,233,0.14),transparent_28%),linear-gradient(135deg,#020617_0%,#0f172a_45%,#111827_100%)] p-8">
        <div class="grid gap-8 xl:grid-cols-[1.2fr_0.8fr]">
            <div class="space-y-6">
                <div class="inline-flex items-center gap-2 rounded-full border border-amber-300/20 bg-amber-300/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-amber-100">
                    Finny
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-300"></span>
                    Sistema de pontuacao
                </div>

                <div class="space-y-3">
                    <h1 class="max-w-3xl text-4xl font-black tracking-tight text-white md:text-5xl">
                        Conhe&ccedil;a o Finny
                    </h1>
                    <p class="max-w-3xl text-lg leading-8 text-slate-300">
                        Seu golden retriever virtual que celebra suas conquistas financeiras. Finny acompanha seus habitos, reage ao seu momento e te ajuda a manter constancia com medalhas, XP e foco claro.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <article class="rounded-3xl border border-white/10 bg-white/5 p-5 backdrop-blur">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Pontuacao</p>
                                <p class="mt-3 text-4xl font-black text-white">{{ $finny['score'] }}</p>
                            </div>
                            <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-300/15 text-3xl">
                                &#128054;
                            </div>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-slate-300">{{ $finny['mood']['headline'] }}</p>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-white/5 p-5 backdrop-blur">
                        <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Nivel e XP</p>
                        <div class="mt-3 flex items-end gap-3">
                            <p class="text-4xl font-black text-white">{{ $finny['level'] }}</p>
                            <p class="pb-1 text-sm text-slate-400">{{ number_format($finny['xp']) }} XP</p>
                        </div>
                        <div class="mt-4 h-3 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full bg-gradient-to-r from-amber-300 via-orange-400 to-rose-400" style="width: {{ $finny['level_progress'] }}%"></div>
                        </div>
                        <p class="mt-3 text-xs uppercase tracking-[0.16em] text-slate-400">
                            {{ number_format($finny['xp_in_level']) }} / {{ number_format($finny['xp_for_next_level']) }} XP neste nivel
                        </p>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-white/5 p-5 backdrop-blur">
                        <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Sequencia atual</p>
                        <div class="mt-3 flex items-end gap-3">
                            <p class="text-4xl font-black text-white">{{ $finny['current_streak'] }}</p>
                            <p class="pb-1 text-sm text-slate-400">dias seguidos</p>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-slate-300">
                            Melhor marca: {{ $finny['best_streak'] }} dias. Cada novo registro reforca o humor do Finny.
                        </p>
                    </article>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-1">
                <article class="rounded-[2rem] border bg-gradient-to-br {{ $moodClasses['panel'] }} p-6 {{ $moodClasses['glow'] }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] {{ $moodClasses['badge'] }}">
                                {{ $finny['mood']['label'] }}
                            </span>
                            <h2 class="mt-4 text-2xl font-black text-white">{{ $finny['mood']['headline'] }}</h2>
                            <p class="mt-3 max-w-md text-sm leading-7 text-slate-200">{{ $finny['mood']['message'] }}</p>
                        </div>
                        <div class="inline-flex h-20 w-20 items-center justify-center rounded-[1.75rem] border border-white/10 bg-white/10 text-5xl">
                            &#128054;
                        </div>
                    </div>
                </article>

                <article class="rounded-[2rem] border bg-gradient-to-br {{ $recentClasses['panel'] }} p-6 {{ $recentClasses['glow'] }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] {{ $recentClasses['badge'] }}">
                                {{ $finny['recent_achievement'] ? 'Conquista desbloqueada' : 'Proximo foco' }}
                            </span>
                            @if($finny['recent_achievement'])
                                <h2 class="mt-4 text-2xl font-black text-white">{{ $finny['recent_achievement']['title'] }}</h2>
                                <p class="mt-3 text-sm leading-7 text-slate-200">{{ $finny['recent_achievement']['description'] }}</p>
                            @else
                                <h2 class="mt-4 text-2xl font-black text-white">{{ $finny['focus_area']['title'] }}</h2>
                                <p class="mt-3 text-sm leading-7 text-slate-200">{{ $finny['focus_area']['description'] }}</p>
                            @endif
                        </div>
                        <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl {{ $recentClasses['icon'] }}">
                            <span class="h-8 w-8">{!! $this->achievementIcon($finny['recent_achievement']['icon'] ?? 'sparkles') !!}</span>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-[2rem] border border-white/10 bg-slate-950/80 p-6 shadow-[0_18px_70px_rgba(2,6,23,0.32)]">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Score breakdown</p>
                    <h2 class="mt-2 text-2xl font-black text-white">O que mais pesa na pontuacao do Finny</h2>
                </div>
                <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-200">
                    {{ $finny['badges_unlocked'] }} medalhas
                </span>
            </div>

            <div class="mt-8 space-y-5">
                @foreach($finny['score_breakdown'] as $key => $value)
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

        <div class="rounded-[2rem] border border-white/10 bg-slate-950/80 p-6 shadow-[0_18px_70px_rgba(2,6,23,0.32)]">
            <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Foco sugerido</p>
            <h2 class="mt-2 text-2xl font-black text-white">{{ $finny['focus_area']['title'] }}</h2>
            <p class="mt-4 text-sm leading-7 text-slate-300">{{ $finny['focus_area']['description'] }}</p>

            <div class="mt-8 grid gap-3">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Receitas do mes</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-300">R$ {{ number_format($finny['stats']['current_month_income'], 2, ',', '.') }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Despesas do mes</p>
                    <p class="mt-2 text-2xl font-bold text-rose-300">R$ {{ number_format($finny['stats']['current_month_expenses'], 2, ',', '.') }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Economia do mes</p>
                    <p class="mt-2 text-2xl font-bold text-amber-200">R$ {{ number_format($finny['stats']['current_month_savings'], 2, ',', '.') }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="rounded-[2rem] border border-white/10 bg-slate-950/80 p-6 shadow-[0_18px_70px_rgba(2,6,23,0.32)]">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.18em] text-slate-400">Conquistas e medalhas</p>
                <h2 class="mt-2 text-2xl font-black text-white">Finny celebra cada marco importante</h2>
            </div>
            <p class="max-w-xl text-sm leading-7 text-slate-300">
                Ganhe medalhas por sequencias de economia, manter-se dentro do orcamento e alcancar metas. As desbloqueadas ficam registradas no seu historico.
            </p>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($finny['achievements'] as $achievement)
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

