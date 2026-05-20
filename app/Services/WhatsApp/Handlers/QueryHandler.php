<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Log;

class QueryHandler extends BaseHandler
{
    private const QUERY_ACTIONS = [
        'query_balance',
        'query_expenses',
        'query_income',
        'query_transactions',
        'query_category',
        'query_savings',
        'query_budgets',
        'query_evolution',
        'query_projections',
        'query_income_source',
        'query_categories',
    ];

    public function canHandle(?string $action): bool
    {
        return in_array($action, self::QUERY_ACTIONS, true);
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $rawMessage = $result['_resolved_message'] ?? $job->message;

        try {
            $reply = $this->buildQueryReply($user, $action, $result['reply'] ?? '', $rawMessage);
        } catch (\Throwable $exception) {
            Log::error('Falha ao montar resposta de consulta via WhatsApp', [
                'user_id' => $user->id,
                'action' => $action,
                'message' => $rawMessage,
                'error' => $exception->getMessage(),
            ]);

            $reply = 'Não consegui consultar esses dados agora. Tente novamente em instantes.';
        }

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'query',
            'entities' => $this->extractConversationEntities($action, $rawMessage),
        ]);

        Log::info('Consulta processada via WhatsApp', [
            'user_id' => $user->id,
            'action' => $action,
            'reply_length' => mb_strlen($reply),
        ]);

        $this->sendResponse($job, $reply, $user);

        return true;
    }

    private function buildQueryReply(User $user, ?string $action, string $fallbackReply, string $rawMessage): string
    {
        return match ($action) {
            'query_transactions' => $this->buildTransactionsReply($user, $rawMessage),
            'query_category' => $this->buildCategoryReply($user, $fallbackReply, $rawMessage),
            'query_budgets' => $this->buildBudgetsReply($user, $rawMessage),
            default => $fallbackReply,
        };
    }

    private function buildTransactionsReply(User $user, string $rawMessage): string
    {
        $message = mb_strtolower($rawMessage);
        $type = null;
        $title = 'Últimas transações';

        if (str_contains($message, 'gasto') || str_contains($message, 'despesa')) {
            $type = 'expense';
            $title = 'Seus últimos gastos';
        } elseif (str_contains($message, 'receita') || str_contains($message, 'ganho') || str_contains($message, 'entrada')) {
            $type = 'income';
            $title = 'Suas últimas receitas';
        }

        $transactions = Transaction::query()
            ->with('category')
            ->where('user_id', $user->id)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest('date')
            ->latest('id')
            ->limit(5)
            ->get();

        if ($transactions->isEmpty()) {
            return match ($type) {
                'expense' => 'Você ainda não tem gastos registrados.',
                'income' => 'Você ainda não tem receitas registradas.',
                default => 'Você ainda não tem transações registradas.',
            };
        }

        $lines = $transactions->map(function (Transaction $transaction) {
            $date = $transaction->date?->format('d/m') ?? now()->format('d/m');
            $label = $transaction->description ?: ($transaction->type === 'income' ? 'Receita' : 'Gasto');
            $category = $transaction->category?->name ? " ({$transaction->category->name})" : '';
            $amount = number_format((float) $transaction->amount, 2, ',', '.');

            return "- {$date} - {$label}{$category}: R$ {$amount}";
        })->implode("
");

        return "{$title}:
{$lines}";
    }

    private function buildCategoryReply(User $user, string $fallbackReply, string $rawMessage): string
    {
        $searchTerm = $this->extractCategorySearchTerm($rawMessage);

        if ($searchTerm === null) {
            return $fallbackReply;
        }

        $transactions = Transaction::query()
            ->with('category')
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where(function ($query) use ($searchTerm) {
                $query->whereRaw('LOWER(description) LIKE ?', ['%' . $searchTerm . '%'])
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->whereRaw('LOWER(name) LIKE ?', ['%' . $searchTerm . '%']));
            })
            ->latest('date')
            ->latest('id')
            ->get();

        if ($transactions->isEmpty()) {
            return "Não encontrei gastos com {$searchTerm} ainda.";
        }

        $count = $transactions->count();
        $total = number_format((float) $transactions->sum('amount'), 2, ',', '.');
        $latest = $transactions->first();
        $latestDate = $latest->date?->format('d/m') ?? now()->format('d/m');
        $label = $latest->description ?: $latest->category?->name ?: ucfirst($searchTerm);

        if ($count === 1) {
            return "Encontrei 1 gasto com {$label}, no valor de R$ {$total}, em {$latestDate}.";
        }

        return "Encontrei {$count} gastos com {$label}, somando R$ {$total}. O mais recente foi em {$latestDate}.";
    }

    private function extractCategorySearchTerm(string $rawMessage): ?string
    {
        $message = mb_strtolower(trim($rawMessage));
        $message = preg_replace('/[?!.]+/u', '', $message);

        $patterns = [
            '/gastos?\s+com\s+(.+)$/u',
            '/despesas?\s+com\s+(.+)$/u',
            '/gastei\s+com\s+(.+)$/u',
            '/com\s+(.+)$/u',
            '/de\s+(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                $term = trim($matches[1]);
                $term = preg_replace('/\b(hoje|ontem|esse mes|este mes|mês|mes|ultimos?|últimos?)\b/u', '', $term);
                $term = trim($term);

                if ($term !== '') {
                    return $term;
                }
            }
        }

        return null;
    }

    private function buildBudgetsReply(User $user, string $rawMessage): string
    {
        $budgets = Budget::query()
            ->with('category')
            ->where('user_id', $user->id)
            ->where('year', now()->year)
            ->where(function ($query) {
                $query->where(function ($monthly) {
                    $monthly->where('period', 'monthly')->where('month', now()->month);
                })->orWhere('period', 'yearly');
            })
            ->orderBy('period')
            ->orderByDesc('amount')
            ->get();

        if ($budgets->isEmpty()) {
            return 'Você ainda não tem orçamentos cadastrados para este período.';
        }

        $searchTerm = $this->extractBudgetSearchTerm($rawMessage);

        if ($searchTerm !== null) {
            $normalizedTerm = mb_strtolower($searchTerm);

            $budgets = $budgets->filter(function (Budget $budget) use ($normalizedTerm) {
                $categoryName = mb_strtolower((string) ($budget->category?->name ?? ''));

                return $categoryName !== '' && str_contains($categoryName, $normalizedTerm);
            })->values();

            if ($budgets->isEmpty()) {
                return "Não encontrei orçamento para {$searchTerm} neste período.";
            }
        }

        $periodLabel = now()->translatedFormat('F/Y');

        $lines = $budgets->map(function (Budget $budget) {
            $amount = number_format((float) $budget->amount, 2, ',', '.');
            $spent = number_format((float) $budget->spent, 2, ',', '.');
            $remaining = number_format((float) $budget->remaining, 2, ',', '.');
            $period = $budget->period === 'yearly' ? 'anual' : 'mensal';
            $category = $budget->category?->name ?? 'Sem categoria';

            return "- {$category}: limite R$ {$amount} | gasto R$ {$spent} | restante R$ {$remaining} ({$period})";
        })->implode("
");

        return "Seus orçamentos de {$periodLabel}:
{$lines}";
    }

    private function extractBudgetSearchTerm(string $rawMessage): ?string
    {
        $message = mb_strtolower(trim($rawMessage));
        $message = preg_replace('/[?!.]+/u', '', $message);

        $patterns = [
            '/orcamento\s+(?:para|de)\s+(.+)$/u',
            '/orçamento\s+(?:para|de)\s+(.+)$/u',
            '/meu\s+orcamento\s+(?:para|de)\s+(.+)$/u',
            '/meu\s+orçamento\s+(?:para|de)\s+(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                $term = trim($matches[1]);
                $term = preg_replace('/\b(geral|gerais|do mes|do mês|desse mes|desse mês|este mes|este mês)\b/u', '', $term);
                $term = trim($term);

                if ($term !== '') {
                    return ucfirst($term);
                }
            }
        }

        return null;
    }

    private function extractConversationEntities(?string $action, string $rawMessage): array
    {
        return match ($action) {
            'query_budgets' => array_filter([
                'topic' => 'budget',
                'category_name' => $this->extractBudgetSearchTerm($rawMessage),
            ]),
            'query_category' => array_filter([
                'topic' => 'expense_category',
                'category_name' => $this->extractCategorySearchTerm($rawMessage),
            ]),
            'query_transactions' => ['topic' => 'transactions'],
            default => [],
        };
    }
}
