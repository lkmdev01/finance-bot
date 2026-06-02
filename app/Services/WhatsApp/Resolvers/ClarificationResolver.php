<?php

namespace App\Services\WhatsApp\Resolvers;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;
use App\Services\WhatsApp\TransactionActionMessageParser;
use App\Services\WhatsApp\ReminderMessageParser;
use App\Services\WhatsApp\NoteMessageParser;
use App\Services\WhatsApp\TransactionSplitMessageParser;

class ClarificationResolver
{
    use NormalizesWhatsAppText;
    public function __construct(
        private readonly TransactionActionMessageParser $transactionActionMessageParser,
        private readonly ReminderMessageParser $reminderMessageParser,
        private readonly NoteMessageParser $noteMessageParser,
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
            'create_note_content' => true,
            'drive_save_waiting_media' => in_array($classification, ['default', 'drive_save', 'drive_needs_file'], true),
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
            'create_note_content' => $this->buildNoteContentClarificationResult($message, $state),
            'drive_save_waiting_media' => $this->buildDriveWaitMediaClarificationResult($message, $state),
            'select_credit_card' => $this->buildSelectCreditCardClarificationResult($message, $state),
            'select_card_payment_method' => $this->buildSelectCardPaymentMethodClarificationResult($message, $state),
            'select_bank_account' => $this->buildSelectBankAccountClarificationResult($message, $state),
            default => null,
        };
    }

    private function buildDriveWaitMediaClarificationResult(string $message, array $state): ?array
    {
        $incomingMediaId = (int) ($state['last_entities']['incoming_media_id'] ?? 0);
        if ($incomingMediaId <= 0) {
            return [
                'handled' => true,
                'reply' => "Ainda nao recebi o arquivo.\n\nMe envie o arquivo/foto/audio e eu salvo no Drive.",
                'action' => null,
                'metadata' => [
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                ],
            ];
        }

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_drive_file',
                'drive_data' => [
                    'incoming_media_id' => $incomingMediaId,
                ],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildNoteContentClarificationResult(string $message, array $state): ?array
    {
        $pending = is_array($state['pending_payload']['note_data'] ?? null) ? $state['pending_payload']['note_data'] : [];

        $parsed = $this->noteMessageParser->parseCreate('anota: '.$message);
        if ($parsed === null) {
            // Try raw message too (user might reply with "nota: ...").
            $parsed = $this->noteMessageParser->parseCreate($message);
        }

        if ($parsed === null) {
            return [
                'handled' => true,
                'reply' => "Nao consegui entender a nota. Pode mandar em uma frase?\n\nExemplos:\n- anota: ideia para o projeto X\n- anota que preciso falar com Joao",
                'action' => null,
                'metadata' => [
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                ],
            ];
        }

        $noteData = array_merge($pending, $parsed);

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_note',
                'note_data' => $noteData,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildSelectBankAccountClarificationResult(string $message, array $state): ?array
    {
        $pending = $state['pending_payload']['transaction_data'] ?? [];
        $normalized = $this->normalizeText($message);
        $availableAccounts = $state['pending_payload']['available_bank_accounts'] ?? [];

        if (str_contains($normalized, 'cadastrar') || str_contains($normalized, 'criar conta') || str_contains($normalized, 'adicionar conta')) {
            $url = rtrim((string) config('app.url'), '/').'/bank-accounts/create';

            return [
                'handled' => true,
                'reply' => "Para cadastrar uma conta, use este link:\n{$url}\n\nDepois me diga qual conta voce quer usar aqui.",
                'action' => null,
                'metadata' => [
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                ],
            ];
        }

        if ($this->looksLikeBankAccountsListRequest($normalized)) {
            $reply = $this->buildBankAccountsListReply($availableAccounts);
            if ($reply === null) {
                $url = rtrim((string) config('app.url'), '/').'/bank-accounts/create';
                $reply = "Voce ainda nao tem contas cadastradas.\n\nCadastre aqui:\n{$url}\n\nDepois me diga qual conta voce quer usar (ex.: \"usar Itau\"), ou responda \"caixa\" para usar o saldo geral.";
            }

            return [
                'handled' => true,
                'reply' => $reply,
                'action' => null,
                'metadata' => [
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                ],
            ];
        }

        if ($this->looksLikeCashSelection($normalized)) {
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
        $cleaned = preg_replace('/\\b(?:usar|use|na|no|conta|saldo|debito|credito)\\b/iu', ' ', $message) ?? $message;
        $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned)) ?? $cleaned;
        $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B-:");

        if ($cleaned === '') {
            return null;
        }

        $pending['payment_method'] = 'debit';

        $matchedAccount = $this->matchAvailableBankAccount($cleaned, $availableAccounts);
        if ($availableAccounts !== [] && $matchedAccount === null) {
            $reply = "Eu nao encontrei essa conta. Responda com o nome exato, ou diga \"contas\" para eu listar.";
            $list = $this->buildBankAccountsListReply($availableAccounts);
            if ($list) {
                $reply .= "\n\n".$list;
            }

            return [
                'handled' => true,
                'reply' => $reply,
                'action' => null,
                'metadata' => [
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                ],
            ];
        }

        if ($matchedAccount !== null) {
            $pending['bank_account_id'] = $matchedAccount['id'] ?? null;
            $pending['bank_account_name'] = $matchedAccount['name'] ?? mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
        } else {
            $pending['bank_account_name'] = mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
        }
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
                'reply' => 'Entendi. Estou usando esse cartao para registrar o gasto.',
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
        if (str_contains($normalized, 'credito')) {
            $method = 'credit';
        } elseif (str_contains($normalized, 'debito') || $this->looksLikeBalanceFallback($message)) {
            $method = 'debit';
        }

        if ($method === null) {
            return null;
        }

        $pending = $state['pending_payload']['transaction_data'] ?? [];
        $pending['payment_method'] = $method;

        $preferences = [];
        $cardName = $state['pending_payload']['card_name'] ?? ($pending['credit_card_name'] ?? null);
        if (is_string($cardName) && trim($cardName) !== '') {
            $key = app(\App\Services\WhatsApp\IncomingMessageNormalizer::class)->normalize($cardName);
            if ($key !== '') {
                $preferences = [
                    'card_payment_method' => [
                        $key => $method,
                    ],
                ];
            }
        }

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
                    'preferences' => $preferences,
                ],
            ],
        ];
    }

    private function looksLikeBalanceFallback(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        foreach (['usar saldo', 'no saldo', 'saldo', 'usar caixa', 'caixa', 'debito', 'no debito'] as $needle) {
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
            'cartao padrao',
            'padrao',
            'default',
        ];

        foreach ($defaultPatterns as $pattern) {
            if (str_contains($normalized, $this->normalizeText($pattern))) {
                return 'default';
            }
        }

        // Use the same normalization layer as the rest of the bot so mojibake (e.g. "cartÃ£o")
        // does not break the extraction.
        $cleaned = app(\App\Services\WhatsApp\IncomingMessageNormalizer::class)->clean($message);
        $cleaned = preg_replace('/\b(?:no|na|pelo|pela|via|com|cartao|cartão|de|do|da|credito|crédito|debito|débito)\b/iu', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned)) ?? $cleaned;
        $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B-:");

        if ($cleaned === '') {
            return null;
        }

        return mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
    }

    private function looksLikeBankAccountsListRequest(string $normalizedMessage): bool
    {
        foreach (['contas', 'listar contas', 'lista contas', 'quais contas', 'ver contas', 'minhas contas'] as $needle) {
            if (str_contains($normalizedMessage, $this->normalizeText($needle))) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCashSelection(string $normalizedMessage): bool
    {
        foreach (['saldo', 'usar saldo', 'caixa', 'usar caixa', 'debito', 'no saldo', 'no debito'] as $needle) {
            if (str_contains($normalizedMessage, $this->normalizeText($needle))) {
                return true;
            }
        }

        return false;
    }

    private function buildBankAccountsListReply(array $accounts): ?string
    {
        if ($accounts === []) {
            return null;
        }

        $lines = [];
        foreach ($accounts as $account) {
            $name = trim((string) ($account['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $lines[] = "- {$name}";
        }

        if ($lines === []) {
            return null;
        }

        $list = implode("\n", $lines);

        return "Suas contas ativas:\n{$list}\n\nResponda com o nome (ex.: \"usar Itau\") ou diga \"caixa\" para usar o saldo geral.";
    }

    private function matchAvailableBankAccount(string $inputName, array $accounts): ?array
    {
        if ($accounts === []) {
            return null;
        }

        $input = $this->normalizeText($inputName);
        if ($input === '') {
            return null;
        }

        $exact = [];
        foreach ($accounts as $account) {
            $name = (string) ($account['name'] ?? '');
            $norm = $this->normalizeText($name);
            if ($norm === '') {
                continue;
            }

            if ($norm === $input) {
                $exact[] = $account;
            }
        }

        if (count($exact) === 1) {
            return $exact[0];
        }

        if ($exact !== []) {
            return null;
        }

        $partial = [];
        foreach ($accounts as $account) {
            $name = (string) ($account['name'] ?? '');
            $norm = $this->normalizeText($name);
            if ($norm === '') {
                continue;
            }

            if (str_contains($norm, $input) || str_contains($input, $norm)) {
                $partial[] = $account;
            }
        }

        if (count($partial) === 1) {
            return $partial[0];
        }

        return null;
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
            if (str_contains($normalized, 'credito')) {
                $transactionData['payment_method'] = 'credit';
            } elseif (str_contains($normalized, 'debito')) {
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

