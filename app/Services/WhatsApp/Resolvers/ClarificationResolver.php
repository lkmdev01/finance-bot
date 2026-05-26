<?php

namespace App\Services\WhatsApp\Resolvers;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;
use App\Services\WhatsApp\TransactionActionMessageParser;
use App\Services\WhatsApp\ReminderMessageParser;
use App\Services\WhatsApp\TransactionSplitMessageParser;

class ClarificationResolver
{
    use NormalizesWhatsAppText;
    public function __construct(
        private readonly TransactionActionMessageParser $transactionActionMessageParser,
        private readonly ReminderMessageParser $reminderMessageParser,
        private readonly TransactionSplitMessageParser $transactionSplitMessageParser,
    ) {}

    public function shouldResolve(string $classification, array $state): bool
    {
        return match ($state['pending_intent'] ?? null) {
            'update_budget_category', 'delete_budget_category' => in_array($classification, ['default', 'budget_query'], true),
            'edit_transaction_details' => in_array($classification, ['default', 'transaction_edit'], true),
            'split_transaction_details' => in_array($classification, ['default', 'transaction_split'], true),
            'create_recurring_transaction_amount' => in_array($classification, ['default', 'transaction_create'], true),
            'create_reminder_schedule' => in_array($classification, ['default', 'reminder_create', 'reminder_needs_schedule'], true),
            'select_credit_card', 'select_card_payment_method', 'select_bank_account' => true,
            default => false,
        };
    }

    public function resolve(string $message, array $state): ?array
    {
        return match ($state['pending_intent'] ?? null) {
            'update_budget_category' => $this->buildBudgetClarificationResult('update_budget', $message, $state),
            'delete_budget_category' => $this->buildBudgetClarificationResult('delete_budget', $message, $state),
            'edit_transaction_details' => $this->buildTransactionEditClarificationResult($message, $state),
            'split_transaction_details' => $this->buildTransactionSplitClarificationResult($message, $state),
            'create_recurring_transaction_amount' => $this->buildRecurringAmountClarificationResult($message, $state),
            'create_reminder_schedule' => $this->buildReminderScheduleClarificationResult($message, $state),
            'select_credit_card' => $this->buildSelectCreditCardClarificationResult($message, $state),
            'select_card_payment_method' => $this->buildSelectCardPaymentMethodClarificationResult($message, $state),
            'select_bank_account' => $this->buildSelectBankAccountClarificationResult($message, $state),
            default => null,
        };
    }

    private function buildSelectBankAccountClarificationResult(string $message, array $state): ?array
    {
        $pending = $state['pending_payload']['transaction_data'] ?? [];
        $normalized = $this->normalizeText($message);

        if ($this->looksLikeBalanceFallback($message)) {
            // Keep whatever bank_account_name we prefilled (usually Caixa).
            $pending['payment_method'] = 'debit';

            return [
                'handled' => false,
                'result' => [
                    'reply' => 'Entendi. Vou registrar no saldo da conta.',
                    'action' => 'create_transaction',
                    'transaction_data' => $pending,
                    '_resolved_message' => $message,
                    '_conversation_metadata' => [
                        'clear_pending' => true,
                        'reply_kind' => 'action',
                    ],
                ],
            ];
        }

        // Treat any non-empty text as an account name.
        $cleaned = preg_replace('/\b(?:usar|use|na|no|conta|saldo|debito|d[eé]bito)\b/iu', ' ', $message) ?? $message;
        $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned)) ?? $cleaned;
        $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B-:");

        if ($cleaned === '') {
            return null;
        }

        $pending['payment_method'] = 'debit';
        $pending['bank_account_name'] = mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
        unset($pending['credit_card_id'], $pending['credit_card_name'], $pending['use_default_card']);

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_transaction',
                'transaction_data' => $pending,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildSelectCreditCardClarificationResult(string $message, array $state): ?array
    {
        if ($this->looksLikeBalanceFallback($message)) {
            $pending = $state['pending_payload']['transaction_data'] ?? [];
            unset($pending['credit_card_id'], $pending['credit_card_name'], $pending['use_default_card']);
            $pending['payment_method'] = 'debit';

            return [
                'handled' => false,
                'result' => [
                    'reply' => 'Entendi. Vou registrar no saldo da conta.',
                    'action' => 'create_transaction',
                    'transaction_data' => $pending,
                    '_resolved_message' => $message,
                    '_conversation_metadata' => [
                        'clear_pending' => true,
                        'reply_kind' => 'action',
                    ],
                ],
            ];
        }

        $creditCardName = $this->extractCreditCardName($message);
        if ($creditCardName === null) {
            return null;
        }

        $pending = $state['pending_payload']['transaction_data'] ?? [];

        if ($creditCardName === 'default') {
            $pending['use_default_card'] = true;
        } else {
            $pending['credit_card_name'] = $creditCardName;
        }

        return [
            'handled' => false,
            'result' => [
                'reply' => 'Entendi. Estou usando esse cartão para registrar o gasto.',
                'action' => 'create_transaction',
                'transaction_data' => $pending,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildSelectCardPaymentMethodClarificationResult(string $message, array $state): ?array
    {
        $normalized = $this->normalizeText($message);

        $method = null;
        if (str_contains($normalized, 'credito') || str_contains($normalized, 'crÃ©dito')) {
            $method = 'credit';
        } elseif (str_contains($normalized, 'debito') || str_contains($normalized, 'dÃ©bito') || $this->looksLikeBalanceFallback($message)) {
            $method = 'debit';
        }

        if ($method === null) {
            return null;
        }

        $pending = $state['pending_payload']['transaction_data'] ?? [];
        $pending['payment_method'] = $method;

        if ($method === 'debit') {
            // "Debito" aqui significa "a vista no saldo". Nao tente inferir uma conta pelo nome do cartao.
            unset($pending['credit_card_id'], $pending['credit_card_name'], $pending['use_default_card']);
        }

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_transaction',
                'transaction_data' => $pending,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function looksLikeBalanceFallback(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        foreach (['usar saldo', 'saldo', 'na conta', 'conta', 'debito', 'dÃ©bito'] as $needle) {
            if (str_contains($normalized, $this->normalizeText($needle))) {
                return true;
            }
        }

        return false;
    }

    private function extractCreditCardName(string $message): ?string
    {
        $normalized = $this->normalizeText($message);

        $defaultPatterns = [
            'usar cartao padrao',
            'usar cartao padrão',
            'usar cartão padrao',
            'usar cartão padrão',
            'cartao padrao',
            'cartão padrão',
            'padrao',
            'default',
        ];

        foreach ($defaultPatterns as $pattern) {
            if (str_contains($normalized, $this->normalizeText($pattern))) {
                return 'default';
            }
        }

        $cleaned = preg_replace('/\b(?:no|na|pelo|pela|via|com|cartao|cartão|de|do|da|credito|crédito|debito|débito)\b/iu', ' ', $message) ?? $message;
        $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned)) ?? $cleaned;
        $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B-:");

        if ($cleaned === '') {
            return null;
        }

        return mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
    }

    private function buildBudgetClarificationResult(string $action, string $message, array $state): ?array
    {
        $categoryName = trim($message, " \t\n\r\0\x0B .,:;!?");
        if ($categoryName === '') {
            return null;
        }

        $budgetData = $state['pending_payload']['budget_data'] ?? [];
        $budgetData['category_name'] = $categoryName;

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => $action,
                'budget_data' => $budgetData,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildTransactionEditClarificationResult(string $message, array $state): ?array
    {
        $transactionData = $this->transactionActionMessageParser->parseEdit($message, $state) ?? [];
        $pending = $state['pending_payload']['transaction_data'] ?? [];

        if ($transactionData === [] && empty($pending['transaction_id'])) {
            return null;
        }

        // In edit follow-ups we often get short messages without a verb, like:
        // "para 28", "28", "foi no debito", "de mercado".
        // The main parser is strict (requires an edit verb), so we accept these
        // minimal forms here when we are already in an edit clarification.
        if ($transactionData === []) {
            $normalized = $this->normalizeText($message);

            // Amount-only follow-up.
            $amount = $this->extractAmount($message);
            if ($amount !== null) {
                $transactionData['amount'] = $amount;
            }

            // Payment method-only follow-up.
            if (str_contains($normalized, 'credito') || str_contains($normalized, 'crÃƒÂ©dito')) {
                $transactionData['payment_method'] = 'credit';
            } elseif (str_contains($normalized, 'debito') || str_contains($normalized, 'dÃƒÂ©bito')) {
                $transactionData['payment_method'] = 'debit';
            } elseif (str_contains($normalized, 'pix')) {
                $transactionData['payment_method'] = 'pix';
            }

            // Category-only follow-up (simple heuristic).
            if (preg_match('/\\bde\\s+([\\p{L}\\p{N} _-]+)$/u', trim($message), $matches) === 1) {
                $category = trim((string) ($matches[1] ?? ''));
                $category = trim($category, " \t\n\r\0\x0B-:");
                if ($category !== '' && ! in_array($this->normalizeText($category), ['ontem', 'hoje', 'debito', 'credito', 'pix'], true)) {
                    $transactionData['category_name'] = mb_convert_case($category, MB_CASE_TITLE, 'UTF-8');
                }
            }
        }

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'edit_transaction',
                'transaction_data' => array_merge($pending, $transactionData),
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildTransactionSplitClarificationResult(string $message, array $state): ?array
    {
        $parsed = $this->transactionSplitMessageParser->parse('divide em categorias '.$message) ?? [];
        $pending = $state['pending_payload']['transaction_data'] ?? [];

        if (empty($parsed['split_items'])) {
            return null;
        }

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'split_transaction',
                'transaction_data' => array_merge($pending, $parsed),
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildRecurringAmountClarificationResult(string $message, array $state): ?array
    {
        $amount = $this->extractAmount($message);
        $pending = $state['pending_payload']['recurring_data'] ?? [];

        if ($amount === null || empty($pending['description']) || empty($pending['frequency'])) {
            return null;
        }

        $pending['amount'] = $amount;

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_recurring_transaction',
                'recurring_data' => $pending,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildReminderScheduleClarificationResult(string $message, array $state): ?array
    {
        $pending = $state['pending_payload']['reminder_data'] ?? [];
        $reminderData = $this->reminderMessageParser->parseScheduleFollowUp($message, $pending);

        if ($reminderData === null) {
            return null;
        }

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_reminder',
                'reminder_data' => $reminderData,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function extractAmount(string $message): ?float
    {
        if (! preg_match('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $message, $matches)) {
            return null;
        }

        $raw = str_replace('.', '', $matches[1]);
        $amount = (float) str_replace(',', '.', $raw);

        return $amount > 0 ? $amount : null;
    }
}
