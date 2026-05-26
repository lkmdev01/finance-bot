<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BillingPlanService;
use App\Services\CategoryRecognitionService;
use App\Services\PerformanceMetricsService;
use App\Services\WhatsApp\CompoundTransactionMessageParser;
use App\Services\WhatsApp\FinancialSourceResolver;
use App\Services\WhatsAppFormatter;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CreateTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return in_array($action, ['create_transaction', 'confirm_large_transaction'], true);
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        if ($action === 'confirm_large_transaction' && isset($result['transaction_data'])) {
            $isConfirmation = preg_match('/\b(sim|s|yes|y|confirmo|confirmar|pode|pode sim|ok|tudo bem|claro)\b/i', strtolower($job->message));

            if (! $isConfirmation) {
                $this->sendResponse($job, (string) ($result['reply'] ?? ''), $user);
                return true;
            }

            $action = 'create_transaction';
        }

        if ($action !== 'create_transaction' || ! isset($result['transaction_data'])) {
            return false;
        }

        if ($this->isCompoundFinancialMessage($job->message)) {
            $payloads = app(CompoundTransactionMessageParser::class)->parse($job->message);

            if ($payloads !== []) {
                return $this->handleCompoundTransactions($payloads, $result, $user, $contact, $job);
            }

            $this->sendResponse($job, $this->buildCompoundTransactionReply(), $user);
            return true;
        }

        $billingPlanService = app(BillingPlanService::class);
        if (! $billingPlanService->userCanCreateRecords($user)) {
            $plansUrl = rtrim((string) config('app.url'), '/').'/billing/plans';
            $reply = $billingPlanService->writeAccessMessage($user)
                ."\n\nAssine um plano para voltar a registrar novas informacoes:\n"
                .$plansUrl;
            $this->sendResponse($job, $reply, $user);
            return true;
        }

        $result['transaction_data'] = $this->normalizeTransactionData($result['transaction_data'], $job->message);
        $validation = $this->validateTransactionData($result['transaction_data'], $user);

        if ($validation->fails()) {
            $errors = $validation->errors();

            if ($errors->has('category_id') && $errors->count() === 1) {
                Log::info('Ignorando erro de categoria da IA e prosseguindo sem categoria', [
                    'user_id' => $user->id,
                    'invalid_category_id' => $result['transaction_data']['category_id'] ?? null,
                ]);
                $result['transaction_data']['category_id'] = null;
            } else {
                $metricsService = app(PerformanceMetricsService::class);
                $metricsService->recordError('validation', 'Dados de transacao invalidos');
                $metricsService->recordTransactionSuccess(false, 'whatsapp');

                Log::warning('Dados de transacao invalidos da IA', [
                    'user_id' => $user->id,
                    'errors' => $errors->all(),
                    'data' => $result['transaction_data'],
                ]);

                $this->sendErrorMessage($job, $this->buildValidationGuidanceReply($errors->all(), $job->message));
                return true;
            }
        }

        // Se o usuário indicou pagamento no cartão mas não informamos qual cartão, pedir confirmação/especificação
        // Se a mensagem citou um cartao especifico, mas nao ficou claro se e credito ou debito,
        // pedir esclarecimento antes de persistir para evitar descontar no lugar errado.
        $result['transaction_data'] = $this->inferCardIntentFromRawMessage($result['transaction_data'], $job->message);

        // If the user said "no debito/no saldo" but referenced a card by name, we interpret it as
        // "use the account that matches that institution/name", not the credit limit.
        if (($result['transaction_data']['payment_method'] ?? null) === 'debit'
            && empty($result['transaction_data']['bank_account_name'])
            && ! empty($result['transaction_data']['credit_card_name'])) {
            $result['transaction_data']['bank_account_name'] = $result['transaction_data']['credit_card_name'];
            $result['transaction_data']['credit_card_name'] = null;
        }

        // If user explicitly asked for debit for a card name but we still don't have a matching account,
        // ask for clarification instead of silently falling back to "Caixa"/first account.
        if (($result['transaction_data']['payment_method'] ?? null) === 'debit'
            && ! empty($result['transaction_data']['bank_account_name'])) {
            $sourceResolver = app(\App\Services\WhatsApp\FinancialSourceResolver::class);
            $account = $sourceResolver->findBankAccountByName($user, (string) $result['transaction_data']['bank_account_name']);

            if ($account === null) {
                $fallbackCash = $user->bankAccounts()
                    ->where('is_active', true)
                    ->get()
                    ->first(fn ($acc) => ($acc->type ?? '') === 'cash' || str_contains(mb_strtolower($acc->name ?? ''), 'caixa'));

                $cashName = $fallbackCash?->name ?? 'Caixa';
                $reply = sprintf(
                    'Entendi que voce quer registrar no *debito*, mas eu nao encontrei uma conta "%s" para usar no saldo. Quer usar "%s" (responda: usar saldo) ou me diga o nome da conta certa?',
                    (string) $result['transaction_data']['bank_account_name'],
                    $cashName
                );

                $result['_conversation_metadata'] = [
                    'pending_intent' => 'select_bank_account',
                    'pending_mode' => 'awaiting_clarification',
                    'pending_payload' => [
                        'transaction_data' => array_merge($result['transaction_data'], [
                            'bank_account_name' => $cashName,
                            'payment_method' => 'debit',
                        ]),
                    ],
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                ];

                $this->sendResponse($job, $reply, $user);
                return true;
            }
        }

        // If the user mentioned a specific credit card but didn't say "credito/debito",
        // default to credit. Debit must be explicit ("no debito", "no saldo").
        if (($result['transaction_data']['type'] ?? 'expense') === 'expense'
            && empty($result['transaction_data']['payment_method'])
            && ! empty($result['transaction_data']['credit_card_name'])) {
            $result['transaction_data']['payment_method'] = 'credit';
        }

        if (isset($result['transaction_data']['payment_method'])
            && $result['transaction_data']['payment_method'] === 'credit'
            && empty($result['transaction_data']['credit_card_id'])
            && empty($result['transaction_data']['credit_card_name'])
            && empty($result['transaction_data']['use_default_card'])) {

            if (! $user->creditCards()->where('is_active', true)->exists()) {
                $reply = 'Voce informou pagamento no cartao/credito, mas voce ainda nao tem cartoes ativos cadastrados. '
                    .'Se quiser cadastrar, mande algo como: "registrar cartao de credito Nubank limite de 5000". '
                    .'Se preferir registrar no saldo da conta, responda: "usar saldo".';

                $result['_conversation_metadata'] = [
                    'pending_intent' => 'select_credit_card',
                    'pending_mode' => 'awaiting_clarification',
                    'pending_payload' => [
                        'transaction_data' => $result['transaction_data'],
                    ],
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                ];

                $this->sendResponse($job, $reply, $user);
                return true;
            }

            $reply = 'Você informou pagamento no cartão, mas não identifiquei qual cartão. '
                .'Por favor, responda com o nome do cartão (ex.: "cartão Nubank") ou diga "usar cartão padrão" para prosseguir.';

            $result['_conversation_metadata'] = [
                'pending_intent' => 'select_credit_card',
                'pending_mode' => 'awaiting_clarification',
                'pending_payload' => [
                    'transaction_data' => $result['transaction_data'],
                ],
                'clear_pending' => false,
                'reply_kind' => 'message',
            ];

            $this->sendResponse($job, $reply, $user);
            return true;
        }

        $createdTransaction = $this->persistTransaction($user, $contact, $result['transaction_data'], $job->message);
        $reply = $this->shouldUseGenericTransactionReply($result['transaction_data'], $job->message)
            ? $this->buildGenericTransactionReply($result['transaction_data'])
            : $this->buildCreatedTransactionReply($createdTransaction);

        $metricsService = app(PerformanceMetricsService::class);
        $metricsService->recordTransactionSuccess(true, 'whatsapp');

        Cache::forget("user.{$user->id}.financial_data");
        Cache::forget("user.{$user->id}.financial_projections");

        Log::info('Transacao criada via WhatsApp', [
            'user_id' => $user->id,
            'amount' => $result['transaction_data']['amount'] ?? null,
            'type' => $result['transaction_data']['type'] ?? null,
        ]);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'transactions',
                'transaction_id' => $createdTransaction->id,
                'latest_transaction_id' => $createdTransaction->id,
                'latest_transaction_ids' => [$createdTransaction->id],
                'latest_transaction_description' => $createdTransaction->description,
                'transaction_type' => $createdTransaction->type,
                'category_name' => $createdTransaction->category?->name,
            ],
        ]);

        $this->sendResponse($job, $reply, $user);
        return true;
    }

    private function handleCompoundTransactions(array $payloads, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $createdTransactions = [];

        foreach ($payloads as $payload) {
            $normalizedPayload = $this->normalizeTransactionData($payload, $job->message);
            $validation = $this->validateTransactionData($normalizedPayload, $user);

            if ($validation->fails()) {
                $this->sendErrorMessage($job, $this->buildCompoundTransactionReply());
                return true;
            }

            $createdTransactions[] = $this->persistTransaction($user, $contact, $normalizedPayload, $job->message);
        }

        if ($createdTransactions === []) {
            $this->sendErrorMessage($job, $this->buildCompoundTransactionReply());
            return true;
        }

        $firstTransaction = $createdTransactions[0];
        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'transactions',
                'transaction_id' => $firstTransaction->id,
                'latest_transaction_id' => $firstTransaction->id,
                'latest_transaction_ids' => collect($createdTransactions)->pluck('id')->values()->all(),
                'latest_transaction_description' => $firstTransaction->description,
                'transaction_type' => $firstTransaction->type,
                'category_name' => $firstTransaction->category?->name,
            ],
        ]);

        $this->sendResponse($job, $this->buildCompoundSuccessReply($createdTransactions), $user);
        return true;
    }

    private function persistTransaction(User $user, WhatsAppContact $contact, array $data, string $rawMessage): Transaction
    {
        $categoryRecognition = app(CategoryRecognitionService::class);
        $category = null;

        if (isset($data['category_id'])) {
            $category = Category::where('id', $data['category_id'])
                ->where('user_id', $user->id)
                ->first();
        }

        if (! $category && ! empty($data['category_name'])) {
            $category = $categoryRecognition->findExistingCategoryByName($user, $data['category_name'], $data['type'] ?? 'expense');

            if (! $category) {
                $category = $categoryRecognition->findOrCreateCategory($user, $data['category_name'], $data['type'] ?? 'expense');

                if (! empty($data['category_icon']) && $category->icon === '??') {
                    $category->update(['icon' => $data['category_icon']]);
                }
            }
        }

        if (! $category && ! empty($data['description'])) {
            $category = $categoryRecognition->recognizeCategory($user, $data['description'], (float) ($data['amount'] ?? 0));
        }

        $defaultDescription = ($data['type'] ?? 'expense') === 'income' ? 'Receita' : 'Gasto';
        $finalDescription = ! empty($data['description']) ? $data['description'] : $defaultDescription;
        [$bankAccount, $creditCard] = app(FinancialSourceResolver::class)->resolve($user, $data);

        $paymentMethod = (string) ($data['payment_method'] ?? '');
        if ($paymentMethod === 'credit') {
            // Credit should always impact the card limit/fatura, not the cash balance.
            $bankAccount = null;
        } elseif ($paymentMethod === 'debit' || $paymentMethod === 'pix') {
            // Debit/Pix should always impact the cash/bank balance, not the card limit.
            $creditCard = null;
        }

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'whatsapp_contact_id' => $contact->id,
            'category_id' => $category?->id,
            'bank_account_id' => $bankAccount?->id,
            'credit_card_id' => $creditCard?->id,
            'type' => $data['type'] ?? 'expense',
            'amount' => (float) $data['amount'],
            'description' => $finalDescription,
            'date' => $data['date'] ?? now()->format('Y-m-d'),
            'metadata' => [
                'source' => 'whatsapp',
                'original_message' => $rawMessage,
                'payment_method' => $data['payment_method'] ?? null,
            ],
        ]);

        AuditLog::log('transaction.created', $user->id, Transaction::class, $transaction->id, [
            'source' => 'whatsapp',
            'amount' => $data['amount'],
            'type' => $data['type'] ?? 'expense',
            'category_id' => $category?->id,
            'category_name' => $category?->name,
        ]);

        return $transaction->loadMissing('category', 'bankAccount', 'creditCard');
    }

    private function validateTransactionData(array $data, User $user): ValidatorContract
    {
        $rules = [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ];

        if (isset($data['category_id']) && $data['category_id'] !== null) {
            $rules['category_id'][] = function ($attribute, $value, $fail) use ($user) {
                if (empty($value)) {
                    return;
                }

                $category = Category::where('id', $value)->where('user_id', $user->id)->first();
                if (! $category) {
                    $fail('A categoria selecionada nao existe ou nao pertence a voce.');
                }
            };
        }

        return Validator::make($data, $rules);
    }

    private function normalizeTransactionData(array $data, string $rawMessage): array
    {
        $data = $this->normalizeStringValues($data);
        $description = trim((string) ($data['description'] ?? ''));

        if ($this->isPlaceholderDescription($description)) {
            $data['description'] = null;
            $description = '';
        }

        if ($description !== '') {
            return $data;
        }

        if (! $this->isAmountOnlyMessage($rawMessage)) {
            return $data;
        }

        $data['description'] = null;
        $data['category_id'] = null;
        unset($data['category_name'], $data['category_icon']);

        return $data;
    }

    private function normalizeStringValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = WhatsAppFormatter::normalizeTextEncoding($value);
            }
        }

        return $data;
    }

    private function isCompoundFinancialMessage(string $message): bool
    {
        $msg = mb_strtolower(trim($message));

        if (! $this->looksLikeTransactionIntent($msg)) {
            return false;
        }

        preg_match_all('/(?:r\$\s*)?\d+(?:[.,]\d{1,2})?/u', $msg, $amountMatches);

        if (count($amountMatches[0] ?? []) < 2) {
            return false;
        }

        foreach ([' e ', ',', ';', "\n", ' depois ', ' também ', ' tambem ', ' mais '] as $connector) {
            if (str_contains($msg, $connector)) {
                return true;
            }
        }

        $verbHits = 0;
        foreach (['gastei', 'paguei', 'recebi', 'ganhei', 'entrou'] as $verb) {
            if (str_contains($msg, $verb)) {
                $verbHits++;
            }
        }

        return $verbHits >= 2;
    }

    private function looksLikeTransactionIntent(string $message): bool
    {
        foreach (['gastei', 'gasto', 'paguei', 'recebi', 'ganhei', 'entrou'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function shouldUseGenericTransactionReply(array $data, string $rawMessage): bool
    {
        $description = trim((string) ($data['description'] ?? ''));
        return ($description === '' || $this->isPlaceholderDescription($description))
            && $this->isAmountOnlyMessage($rawMessage);
    }

    private function isAmountOnlyMessage(string $message): bool
    {
        $normalized = mb_strtolower($message);
        $normalized = preg_replace('/[\d\p{P}\p{Sc}]+/u', ' ', $normalized);
        $normalized = preg_replace('/\b(r\$|rs|reais?|real|pix|cart[aã]o|credito|crédito|débito|no|na|de|do|da|em|por|para|com|um|uma|uns|umas|foi|era|só|apenas)\b/u', ' ', $normalized);
        $normalized = preg_replace('/\b(gastei|gasto|paguei|pago|recebi|recebido|ganhei|ganho|entrou|entrada|saída)\b/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $normalized));
        return $normalized === '';
    }

    private function isPlaceholderDescription(string $description): bool
    {
        $normalized = mb_strtolower(trim($description));
        $normalized = str_replace(['*', '_'], '', $normalized);

        return in_array($normalized, [
            'n/a', 'na', 'n a', 'sem descricao', 'sem descrição',
            'sem detalhes', 'sem detalhe', 'nao informado', 'não informado', 'indefinido',
        ], true);
    }

    private function buildCreatedTransactionReply(Transaction $transaction): string
    {
        $amount = number_format((float) $transaction->amount, 2, ',', '.');
        $description = trim((string) $transaction->description);
        $category = trim((string) ($transaction->category?->name ?? ''));
        $paymentMethod = (string) ($transaction->metadata['payment_method'] ?? '');

        $sourceLabel = null;
        if (! empty($transaction->credit_card_id) && $transaction->creditCard?->name) {
            $sourceLabel = 'no cartao '.$transaction->creditCard->name;
        } elseif (! empty($transaction->bank_account_id) && $transaction->bankAccount?->name) {
            $sourceLabel = 'na conta '.$transaction->bankAccount->name;
        } elseif ($paymentMethod === 'debit') {
            $sourceLabel = 'no saldo';
        }

        if ($transaction->type === 'income') {
            if ($description !== '' && $description !== 'Receita' && $category !== '') {
                return "Receita de R$ {$amount} registrada em {$category} ({$description}).";
            }
            if ($description !== '' && $description !== 'Receita') {
                return "Receita de R$ {$amount} registrada como {$description}.";
            }
            if ($category !== '') {
                return "Receita de R$ {$amount} registrada em {$category}.";
            }
            return "Receita de R$ {$amount} registrada.";
        }

        if ($description !== '' && $description !== 'Gasto' && $category !== '') {
            $suffix = $sourceLabel ? " ({$sourceLabel})" : '';
            return "Registrei R$ {$amount} em {$category} ({$description}){$suffix}.";
        }
        if ($description !== '' && $description !== 'Gasto') {
            $suffix = $sourceLabel ? " ({$sourceLabel})" : '';
            return "Registrei R$ {$amount} em {$description}{$suffix}.";
        }
        if ($category !== '') {
            $suffix = $sourceLabel ? " ({$sourceLabel})" : '';
            return "Registrei R$ {$amount} em {$category}{$suffix}.";
        }
        $suffix = $sourceLabel ? " ({$sourceLabel})" : '';
        return "Gasto de R$ {$amount} registrado{$suffix}.";
    }

    private function buildGenericTransactionReply(array $data): string
    {
        $amount = number_format((float) ($data['amount'] ?? 0), 2, ',', '.');
        return ($data['type'] ?? 'expense') === 'income'
            ? "Receita de R$ {$amount} registrada."
            : "Gasto de R$ {$amount} registrado.";
    }

    private function buildCompoundTransactionReply(): string
    {
        return "Nao consegui separar esses lancamentos com seguranca.\n\n"
            ."Tente assim:\n"
            ."• Gastei 32 no Uber e 48 no mercado\n"
            ."• Recebi 420 de freelance e 180 de cashback\n\n"
            ."Se preferir, pode me mandar uma mensagem por vez que eu registro tudo.";
    }

    private function buildCompoundSuccessReply(array $transactions): string
    {
        $lines = collect($transactions)->map(function (Transaction $transaction) {
            $label = $transaction->description ?: ($transaction->category?->name ?? 'Transacao');
            return sprintf('- %s: R$ %s', $label, number_format((float) $transaction->amount, 2, ',', '.'));
        })->implode("\n");

        return "Registrei estes lancamentos:\n{$lines}";
    }

    private function buildValidationGuidanceReply(array $errors, string $rawMessage): string
    {
        $message = mb_strtolower($rawMessage);

        if ($this->isCompoundFinancialMessage($rawMessage)) {
            return $this->buildCompoundTransactionReply();
        }

        if (str_contains($message, 'apaga') || str_contains($message, 'apagar') || str_contains($message, 'exclui') || str_contains($message, 'remove')) {
            return "Nao consegui entender qual transacao voce quer apagar.\n\n"
                ."Tente assim:\n"
                ."• apagar ultima transacao\n"
                ."• apagar Uber de 18 reais\n"
                ."• apagar mercado de ontem";
        }

        if (str_contains($message, 'relatorio') || str_contains($message, 'relatório')) {
            return "Nao consegui entender qual relatorio voce quer gerar.\n\n"
                ."Tente assim:\n"
                ."• me gera um relatorio do mes\n"
                ."• me manda o relatorio em PDF\n"
                ."• relatorio anual em Excel";
        }

        $details = collect($errors)->filter()->implode(' ');
        $base = "Nao consegui entender essa mensagem do jeito que ela veio.\n\n"
            ."Tente mandar em um destes formatos:\n"
            ."• Gastei 50 no supermercado\n"
            ."• Recebi 1000 de salario\n"
            ."• Qual e o meu saldo?";

        return $details !== '' ? $base."\n\nDetalhe: {$details}" : $base;
    }

    private function inferCardIntentFromRawMessage(array $data, string $rawMessage): array
    {
        if (($data['type'] ?? 'expense') !== 'expense') {
            return $data;
        }

        if (! empty($data['payment_method'])) {
            return $data;
        }

        $normalized = mb_strtolower(WhatsAppFormatter::normalizeTextEncoding($rawMessage));
        $ascii = Str::ascii($normalized);

        if (! str_contains($ascii, 'cartao')) {
            return $data;
        }

        // If user explicitly says debit/saldo, treat as debit.
        if (str_contains($ascii, 'debito') || str_contains($ascii, 'saldo')) {
            $data['payment_method'] = 'debit';
            return $data;
        }

        // If user explicitly says credit, treat as credit.
        if (str_contains($ascii, 'credito')) {
            $data['payment_method'] = 'credit';
            return $data;
        }

        // Se o usuario citou apenas "cartao" sem especificar, assumir credito e pedir qual cartao.
        if (empty($data['credit_card_id']) && empty($data['credit_card_name']) && empty($data['bank_account_name']) && empty($data['bank_account_id'])) {
            $data['payment_method'] = 'credit';
        }

        return $data;
    }
}
