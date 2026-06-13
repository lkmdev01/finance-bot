<?php

namespace App\Console\Commands;

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class DedupeSavingsGoalsCommand extends Command
{
    protected $signature = 'savings:dedupe-goals
        {--user= : ID, email ou telefone do usuario}
        {--apply : Renomeia de verdade. Sem isso, roda em dry-run}
        {--include-completed : Inclui metas ja concluidas na analise}';

    protected $description = 'Lista ou renomeia metas duplicadas para melhorar consultas pelo WhatsApp.';

    public function handle(): int
    {
        $user = $this->resolveUser((string) $this->option('user'));

        if (! $user instanceof User) {
            $this->error('Informe um usuario valido com --user=ID|email|telefone.');

            return self::FAILURE;
        }

        $groups = $this->duplicateGroups($user);

        if ($groups->isEmpty()) {
            $this->info('Nenhuma meta duplicada encontrada para esse usuario.');

            return self::SUCCESS;
        }

        $rows = [];
        $updates = [];

        foreach ($groups as $goals) {
            $goals = $goals->sortBy([
                ['target_date', 'asc'],
                ['target_amount', 'asc'],
                ['id', 'asc'],
            ])->values();

            $goals->each(function (SavingsGoal $goal, int $index) use (&$rows, &$updates) {
                $newName = $index === 0 ? $goal->name : $this->suggestName($goal);
                $willRename = $index > 0 && $newName !== $goal->name;

                $rows[] = [
                    'id' => $goal->id,
                    'atual' => $goal->name,
                    'sugestao' => $newName,
                    'valor' => 'R$ '.$this->formatMoney($goal->target_amount),
                    'data_alvo' => $goal->target_date?->format('Y-m-d') ?? 'sem data',
                    'acao' => $willRename ? 'renomear' : 'manter',
                ];

                if ($willRename) {
                    $updates[$goal->id] = $newName;
                }
            });
        }

        $this->table(['id', 'atual', 'sugestao', 'valor', 'data_alvo', 'acao'], $rows);

        if ($updates === []) {
            $this->info('As duplicidades encontradas ja possuem sugestoes equivalentes aos nomes atuais.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn('Dry-run: '.count($updates).' meta(s) seriam renomeadas. Rode com --apply para aplicar.');

            return self::SUCCESS;
        }

        foreach ($updates as $goalId => $newName) {
            SavingsGoal::query()
                ->where('user_id', $user->id)
                ->whereKey($goalId)
                ->update(['name' => $newName]);
        }

        $this->info('Renomeadas '.count($updates).' meta(s) duplicadas.');

        return self::SUCCESS;
    }

    private function resolveUser(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return User::query()
            ->where('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->orWhere('email', $identifier)
            ->orWhere('phone_number', preg_replace('/\D+/', '', $identifier) ?? $identifier)
            ->first();
    }

    /**
     * @return Collection<int, Collection<int, SavingsGoal>>
     */
    private function duplicateGroups(User $user): Collection
    {
        $query = $user->savingsGoals()
            ->orderBy('name')
            ->orderBy('target_date')
            ->orderBy('target_amount');

        if (! $this->option('include-completed')) {
            $query->where('is_completed', false);
        }

        return $query
            ->get()
            ->groupBy(fn (SavingsGoal $goal) => $this->normalizeName((string) $goal->name))
            ->filter(fn (Collection $goals, string $name) => $name !== '' && $goals->count() > 1)
            ->values();
    }

    private function suggestName(SavingsGoal $goal): string
    {
        $parts = [$goal->name];

        if ((float) $goal->target_amount > 0) {
            $parts[] = 'R$ '.$this->formatMoney($goal->target_amount);
        }

        if ($goal->target_date) {
            $parts[] = $goal->target_date->format('m/Y');
        }

        $candidate = implode(' - ', array_values(array_filter($parts)));

        return $candidate !== $goal->name ? $candidate : "{$goal->name} #{$goal->id}";
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return $converted !== false ? $converted : $name;
    }

    private function formatMoney(float|string $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
