<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\PerformanceMetricsService;
use App\Services\CategoryRecognitionService;
use App\Services\PhoneNumberService;
use App\Services\WhatsAppFormatter;
use App\Services\WhatsAppMessageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $phoneNumber, // Número real do usuário
        public readonly string $message,
        public readonly int $userId,
        public readonly ?string $pushName = null, // Nome do WhatsApp
        public readonly ?string $remoteJid = null, // JID original para referência
        public readonly ?string $imageUrl = null, // URL da imagem se houver (para OCR)
    ) {}

    /**
     * Envia resposta via WhatsApp
     */
    private function sendResponse(
        BaileysService $baileysService,
        PhoneNumberService $phoneNumberService,
        string $message,
        User $user
    ): void {
        $recipientJid = $this->getRecipientJid($phoneNumberService);

        try {
            $response = $baileysService->sendTextMessage($recipientJid, $message);

            if ($response->failed()) {
                Log::error('Falha ao enviar mensagem via WhatsApp', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'recipient' => $recipientJid,
                    'user_id' => $user->id,
                ]);
            } else {
                Log::info('Mensagem enviada com sucesso via WhatsApp', [
                    'user_id' => $user->id,
                    'recipient' => $recipientJid,
                    'message_length' => strlen($message),
                    'message_preview' => substr($message, 0, 100),
                ]);
            }
        } catch (\Exception $sendError) {
            Log::error('Exceção ao enviar mensagem via WhatsApp', [
                'error' => $sendError->getMessage(),
                'recipient' => $recipientJid,
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * Execute the job.
     */
    public function handle(
        AIService $aiService,
        BaileysService $baileysService,
        PhoneNumberService $phoneNumberService,
        PerformanceMetricsService $metricsService
    ): void {
        try {
            $user = User::findOrFail($this->userId);

            // Cria ou atualiza o contato usando o número REAL do usuário
            // O phoneNumber aqui é o número real do usuário (users.phone_number)
            $contact = WhatsAppContact::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'phone_number' => $this->phoneNumber, // Número real do usuário
                ],
                [
                    'name' => $this->pushName, // Nome do WhatsApp se disponível
                    'context' => [],
                ]
            );

            // Atualiza o nome do contato se tiver pushName e ainda não tiver nome
            if ($this->pushName && ! $contact->name) {
                $contact->update(['name' => $this->pushName]);
            }

            // Envia feedback visual imediato (processando)
            // $this->sendResponse($baileysService, $phoneNumberService, '⏳ Processando sua mensagem...', $user);

            // Processa a mensagem usando o processador (separação de responsabilidades)
            $startTime = microtime(true);
            $processor = new WhatsAppMessageProcessor($aiService);
            $result = $processor->process($this->message, $user, $contact);
            $processingTime = round((microtime(true) - $startTime) * 1000, 2); // em milissegundos

            // Registra métrica de tempo de resposta da IA
            $metricsService->recordAITime($processingTime, $result['action'] ?? null);

            // Processa ação retornada pela IA
            $action = $result['action'] ?? null;

            if ($action === null && $this->isGreetingMessage()) {
                $result['reply'] = $this->buildGreetingReply($user);
            }

            // Se for confirmação de transação grande, processa
            if ($action === 'confirm_large_transaction' && isset($result['transaction_data'])) {
                // Verifica se a mensagem é uma confirmação
                $isConfirmation = preg_match('/\b(sim|s|yes|y|confirmo|confirmar|pode|pode sim|ok|tudo bem|claro)\b/i', strtolower($this->message));

                if ($isConfirmation) {
                    // Usuário confirmou, cria a transação
                    $action = 'create_transaction';
                } else {
                    // Usuário não confirmou, apenas responde
                    $this->sendResponse($baileysService, $phoneNumberService, $result['reply'], $user);

                    return;
                }
            }

            if ($action === 'create_transaction' && isset($result['transaction_data'])) {
                $result['transaction_data'] = $this->normalizeTransactionData($result['transaction_data']);

                // Valida dados antes de criar transação
                $validation = $this->validateTransactionData($result['transaction_data'], $user);

                if ($validation->fails()) {
                    $errors = $validation->errors();

                    // Se o único erro for a categoria, removemos o category_id e tentamos prosseguir
                    if ($errors->has('category_id') && $errors->count() === 1) {
                        Log::info('Ignorando erro de categoria da IA e prosseguindo sem categoria', [
                            'user_id' => $user->id,
                            'invalid_category_id' => $result['transaction_data']['category_id'] ?? null,
                        ]);
                        $result['transaction_data']['category_id'] = null;
                    } else {
                        // Se houver outros erros (valor, tipo, data), cancelamos
                        $metricsService->recordError('validation', 'Dados de transação inválidos');
                        $metricsService->recordTransactionSuccess(false, 'whatsapp');

                        Log::warning('Dados de transação inválidos da IA', [
                            'user_id' => $user->id,
                            'phone' => $this->phoneNumber,
                            'errors' => $errors->all(),
                            'data' => $result['transaction_data'],
                        ]);

                        $errorMessage = '❌ Não consegui criar a transação. '.
                                       implode(' ', $errors->all());
                        $this->sendErrorMessage($baileysService, $phoneNumberService, $errorMessage);

                        return;
                    }
                }

                $this->createTransaction($user, $contact, $result['transaction_data']);

                if ($this->shouldUseGenericTransactionReply($result['transaction_data'])) {
                    $result['reply'] = $this->buildGenericTransactionReply($result['transaction_data']);
                }

                // Registra métrica de sucesso
                $metricsService->recordTransactionSuccess(true, 'whatsapp');

                // Invalida cache de dados financeiros e projeções após criar transação
                Cache::forget("user.{$user->id}.financial_data");
                Cache::forget("user.{$user->id}.financial_projections");

                Log::info('Transação criada via WhatsApp', [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'phone' => $this->phoneNumber,
                    'amount' => $result['transaction_data']['amount'] ?? null,
                    'type' => $result['transaction_data']['type'] ?? null,
                    'category_id' => $result['transaction_data']['category_id'] ?? null,
                    'description' => $result['transaction_data']['description'] ?? null,
                    'processing_time_ms' => $processingTime,
                    'message_length' => strlen($this->message),
                ]);
            } elseif ($action === 'edit_transaction' && isset($result['transaction_id'])) {
                $this->editTransaction($user, $result['transaction_id'], $result['transaction_data'] ?? []);

                // Invalida cache após editar transação
                Cache::forget("user.{$user->id}.financial_data");
                Cache::forget("user.{$user->id}.financial_projections");

                Log::info('Transação editada via WhatsApp', [
                    'user_id' => $user->id,
                    'transaction_id' => $result['transaction_id'],
                ]);
            } elseif ($action === 'delete_transaction' && isset($result['transaction_id'])) {
                $deletedTransaction = $this->deleteTransaction($user, $result['transaction_id']);
                $result['reply'] = $this->buildDeleteReply($deletedTransaction);

                // Invalida cache após deletar transação
                Cache::forget("user.{$user->id}.financial_data");
                Cache::forget("user.{$user->id}.financial_projections");

                // Registra métrica de sucesso
                $metricsService->recordTransactionSuccess(true, 'whatsapp');

                Log::info('Transação deletada via WhatsApp', [
                    'user_id' => $user->id,
                    'transaction_id' => $result['transaction_id'],
                ]);

            } elseif ($action === 'delete_transaction') {
                // Se não tiver transaction_id, tenta buscar pela descrição ou última transação
                $transactionId = $result['transaction_id'] ?? null;

                if (!$transactionId && isset($result['transaction_data']['description'])) {
                    // Busca pela descrição
                    $description = $result['transaction_data']['description'];
                    $transaction = Transaction::where('user_id', $user->id)
                        ->where('description', 'like', "%{$description}%")
                        ->latest()
                        ->first();

                    if ($transaction) {
                        $transactionId = $transaction->id;
                    }
                }

                if (!$transactionId) {
                    throw new \Exception('Não consegui identificar qual transação deletar. Tente especificar melhor (ex: "apagar última compra" ou "apagar gasto de R$ 50").');
                }

                Log::info('Tentando deletar transação', [
                    'transaction_id' => $transactionId,
                    'user_id' => $user->id,
                ]);

                $deletedTransaction = $this->deleteTransaction($user, $transactionId);
                $result['reply'] = $this->buildDeleteReply($deletedTransaction);

                // Registra métrica de sucesso
                $metricsService->recordTransactionSuccess(true, 'whatsapp');

                Log::info('Transação deletada via WhatsApp', [
                    'user_id' => $user->id,
                    'transaction_id' => $transactionId,
                ]);
            } elseif (in_array($action, ['query_report', 'query_report_pdf', 'query_report_csv', 'query_report_excel'])) {
                // Gera e envia relatório via WhatsApp
                $this->generateAndSendReport($user, $action, $baileysService, $phoneNumberService);
                return;
            } elseif (in_array($action, ['query_balance', 'query_expenses', 'query_income', 'query_transactions', 'query_category', 'query_savings', 'query_budgets', 'query_evolution', 'query_projections', 'query_income_source', 'query_categories'])) {
                $result['reply'] = $this->buildQueryReply($user, $action, $result['reply'] ?? '');

                Log::info('Consulta processada via WhatsApp', [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'phone' => $this->phoneNumber,
                    'action' => $action,
                    'message' => substr($this->message, 0, 100), // Primeiros 100 caracteres
                    'message_length' => strlen($this->message),
                    'reply_length' => strlen($result['reply'] ?? ''),
                    'processing_time_ms' => $processingTime,
                ]);
            }

            // Formata resposta com formatação rica do WhatsApp
            $formattedReply = WhatsAppFormatter::format($result['reply']);

            // Envia resposta via WhatsApp
            $this->sendResponse($baileysService, $phoneNumberService, $formattedReply, $user);
        } catch (\Exception $e) {
            // Registra métrica de erro
            $metricsService->recordError('exception', $e->getMessage());

            Log::error('Erro ao processar mensagem do WhatsApp', [
                'user_id' => $this->userId,
                'phone' => $this->phoneNumber,
                'message' => substr($this->message, 0, 200),
                'message_length' => strlen($this->message),
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 500), // Primeiros 500 caracteres do trace
            ]);

            // Envia mensagem de erro amigável com contexto específico
            $errorMessage = $this->getErrorMessage($e);
            $this->sendErrorMessage($baileysService, $phoneNumberService, $errorMessage);
        }
    }

    /**
     * Valida dados de transação da IA
     */
    private function validateTransactionData(array $data, User $user): \Illuminate\Contracts\Validation\Validator
    {
        $rules = [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ];

        // Valida se a categoria pertence ao usuário
        if (isset($data['category_id']) && $data['category_id'] !== null) {
            $rules['category_id'][] = function ($attribute, $value, $fail) use ($user) {
                if (empty($value)) return;

                $category = Category::where('id', $value)
                    ->where('user_id', $user->id)
                    ->first();

                if (! $category) {
                    $fail('A categoria selecionada não existe ou não pertence a você.');
                }
            };
        }

        return Validator::make($data, $rules);
    }

    /**
     * Cria uma transação baseada nos dados da IA
     */
    private function createTransaction(User $user, WhatsAppContact $contact, array $data): void
    {
        $categoryRecognition = app(CategoryRecognitionService::class);
        $category = null;

        // 1. Prioridade: ID enviado pela IA
        if (isset($data['category_id'])) {
            $category = Category::where('id', $data['category_id'])
                ->where('user_id', $user->id)
                ->first();
        }

        // 2. IA sugeriu uma nova categoria
        if (!$category && !empty($data['category_name'])) {
            $category = $categoryRecognition->findExistingCategoryByName(
                $user,
                $data['category_name'],
                $data['type'] ?? 'expense'
            );

            if (! $category) {
                $category = $categoryRecognition->findOrCreateCategory(
                    $user,
                    $data['category_name'],
                    $data['type'] ?? 'expense'
                );

                // Se a IA também sugeriu um ícone, atualiza se necessário
                if (!empty($data['category_icon']) && $category->icon === '📦') {
                    $category->update(['icon' => $data['category_icon']]);
                }
            }
        }

        // 3. Fallback: Reconhecimento Inteligente (Keywords/Histórico)
        // Só tenta reconhecer pela descrição quando ela realmente existe.
        if (!$category && !empty($data['description'])) {
            $recognitionText = $data['description'];
            $category = $categoryRecognition->recognizeCategory(
                $user,
                $recognitionText,
                (float) ($data['amount'] ?? 0)
            );
        }

        // Descrição padrão baseada no tipo se não houver descrição específica
        $defaultDescription = ($data['type'] ?? 'expense') === 'income' ? 'Receita' : 'Gasto';
        $finalDescription = !empty($data['description']) ? $data['description'] : $defaultDescription;

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'whatsapp_contact_id' => $contact->id,
            'category_id' => $category?->id,
            'type' => $data['type'] ?? 'expense',
            'amount' => (float) $data['amount'],
            'description' => $finalDescription,
            'date' => $data['date'] ?? now()->format('Y-m-d'),
            'metadata' => [
                'source' => 'whatsapp',
                'original_message' => $this->message,
            ],
        ]);

        // Registra log de auditoria
        AuditLog::log(
            'transaction.created',
            $user->id,
            Transaction::class,
            $transaction->id,
            [
                'source' => 'whatsapp',
                'amount' => $data['amount'],
                'type' => $data['type'] ?? 'expense',
                'category_id' => $category?->id,
                'category_name' => $category?->name,
            ]
        );
    }

    /**
     * Evita categorias inventadas quando a mensagem só informa tipo + valor.
     */
    private function normalizeTransactionData(array $data): array
    {
        $description = trim((string) ($data['description'] ?? ''));

        if ($this->isPlaceholderDescription($description)) {
            $data['description'] = null;
            $description = '';
        }

        if ($description !== '') {
            return $data;
        }

        if (! $this->isAmountOnlyMessage($this->message)) {
            return $data;
        }

        $data['description'] = null;
        $data['category_id'] = null;
        unset($data['category_name'], $data['category_icon']);

        return $data;
    }

    /**
     * Para mensagens vagas, a resposta deve refletir exatamente o que foi salvo.
     */
    private function shouldUseGenericTransactionReply(array $data): bool
    {
        $description = trim((string) ($data['description'] ?? ''));

        return ($description === '' || $this->isPlaceholderDescription($description))
            && $this->isAmountOnlyMessage($this->message);
    }

    /**
     * Gera uma resposta consistente para lançamentos sem contexto.
     */
    private function buildGenericTransactionReply(array $data): string
    {
        $amount = number_format((float) ($data['amount'] ?? 0), 2, ',', '.');

        if (($data['type'] ?? 'expense') === 'income') {
            return "✅ Receita de R$ {$amount} registrada!";
        }

        return "✅ Gasto de R$ {$amount} registrado!";
    }

    /**
     * Detecta saudações curtas para responder de forma consistente.
     */
    private function isGreetingMessage(): bool
    {
        $normalized = mb_strtolower(trim($this->message));
        $normalized = preg_replace('/[!?.]+/u', '', $normalized);

        return in_array($normalized, [
            'oi',
            'olá',
            'ola',
            'bom dia',
            'boa tarde',
            'boa noite',
            'e ai',
            'e aí',
            'hey',
            'opa',
        ], true);
    }

    /**
     * Resposta padrão para saudações.
     */
    private function buildGreetingReply(User $user): string
    {
        $firstName = trim((string) explode(' ', trim($user->name))[0]);
        $namePart = $firstName !== '' ? " {$firstName}" : '';

        return "Olá{$namePart}! Eu sou o InovaFinance. Posso registrar gastos e receitas, consultar seu saldo, listar suas últimas transações e gerar relatórios.";
    }

    /**
     * Torna respostas de consulta consistentes e úteis.
     */
    private function buildQueryReply(User $user, string $action, string $fallbackReply): string
    {
        return match ($action) {
            'query_transactions' => $this->buildTransactionsReply($user),
            'query_category' => $this->buildCategoryReply($user, $fallbackReply),
            default => $fallbackReply,
        };
    }

    /**
     * Lista as últimas transações com contexto real.
     */
    private function buildTransactionsReply(User $user): string
    {
        $message = mb_strtolower($this->message);
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
            ->when($type, fn ($query) => $query->where('type', $type))
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

            return "• {$date} - {$label}{$category}: R$ {$amount}";
        })->implode("\n");

        return "{$title}:\n{$lines}";
    }

    /**
     * Resume gastos de uma categoria/termo específico.
     */
    private function buildCategoryReply(User $user, string $fallbackReply): string
    {
        $searchTerm = $this->extractCategorySearchTerm();

        if ($searchTerm === null) {
            return $fallbackReply;
        }

        $transactions = Transaction::query()
            ->with('category')
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where(function ($query) use ($searchTerm) {
                $query->whereRaw('LOWER(description) LIKE ?', ['%'.$searchTerm.'%'])
                    ->orWhereHas('category', function ($categoryQuery) use ($searchTerm) {
                        $categoryQuery->whereRaw('LOWER(name) LIKE ?', ['%'.$searchTerm.'%']);
                    });
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

    /**
     * Extrai o termo principal de uma consulta por categoria.
     */
    private function extractCategorySearchTerm(): ?string
    {
        $message = mb_strtolower(trim($this->message));
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

    /**
     * Detecta mensagens vagas como "gastei 1" ou "recebi 420".
     */
    private function isAmountOnlyMessage(string $message): bool
    {
        $normalized = mb_strtolower($message);
        $normalized = preg_replace('/[\d\p{P}\p{Sc}]+/u', ' ', $normalized);
        $normalized = preg_replace('/\b(r\$|rs|reais?|real|pix|cart[aã]o|credito|cr[eé]dito|d[eé]bito|no|na|de|do|da|em|por|para|com|um|uma|uns|umas|foi|era|s[oó]|apenas)\b/u', ' ', $normalized);
        $normalized = preg_replace('/\b(gastei|gasto|paguei|pago|recebi|recebido|ganhei|ganho|entrou|entrada|sa[ií]da)\b/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));

        return $normalized === '';
    }

    /**
     * Trata descricoes artificiais da IA como ausencia de contexto real.
     */
    private function isPlaceholderDescription(string $description): bool
    {
        $normalized = mb_strtolower(trim($description));
        $normalized = str_replace(['*', '_'], '', $normalized);

        return in_array($normalized, [
            'n/a',
            'na',
            'n a',
            'sem descricao',
            'sem descrição',
            'sem detalhes',
            'sem detalhe',
            'nao informado',
            'não informado',
            'indefinido',
        ], true);
    }

    /**
     * Edita uma transação existente
     */
    private function editTransaction(User $user, $transactionId, array $data): void
    {
        // Se o transactionId não for numérico, tenta buscar por descrição/valor
        if (! is_numeric($transactionId)) {
            $transaction = Transaction::findByDescriptionOrAmount($user, (string) $transactionId);
        } else {
            $transaction = Transaction::where('id', $transactionId)
                ->where('user_id', $user->id)
                ->first();
        }

        if (! $transaction) {
            throw new \Exception('Transação não encontrada para edição.');
        }

        $updateData = [];

        if (isset($data['amount'])) {
            $updateData['amount'] = (float) $data['amount'];
        }

        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }

        if (isset($data['category_id'])) {
            $category = Category::where('id', $data['category_id'])
                ->where('user_id', $user->id)
                ->first();
            $updateData['category_id'] = $category?->id;
        }

        if (isset($data['date'])) {
            $updateData['date'] = $data['date'];
        }

        if (isset($data['type'])) {
            $updateData['type'] = $data['type'];
        }

        $transaction->update($updateData);

        // Registra log de auditoria
        AuditLog::log(
            'transaction.updated',
            $user->id,
            Transaction::class,
            $transaction->id,
            [
                'source' => 'whatsapp',
                'changes' => $updateData,
            ]
        );
    }

    /**
     * Deleta uma transação existente
     */
    private function deleteTransaction(User $user, $transactionId): array
    {

        Log::info('Iniciando deleção de transação', [
            'user_id' => $user->id,
            'input_transaction_id' => $transactionId,
        ]);

        $transaction = $this->resolveTransactionForDeletion($user, $transactionId);

        if (! $transaction) {
            Log::warning('Transação não encontrada para exclusão', [
                'user_id' => $user->id,
                'input_transaction_id' => $transactionId,
            ]);
            throw new \Exception('Transação não encontrada para exclusão.');
        }

        $transactionData = [
            'id' => $transaction->id,
            'amount' => $transaction->amount,
            'type' => $transaction->type,
            'description' => $transaction->description,
            'category_name' => $transaction->category?->name,
        ];

        Log::info('Deletando transação', [
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'description' => $transaction->description,
            'amount' => $transaction->amount,
        ]);

        $transaction->delete();

        // Registra log de auditoria
        AuditLog::log(
            'transaction.deleted',
            $user->id,
            Transaction::class,
            $transaction->id,
            [
                'source' => 'whatsapp',
                'deleted_transaction' => $transactionData,
            ]
        );

        return $transactionData;
    }

    /**
     * Resolve qual transação deve ser apagada sem chutar em caso ambíguo.
     */
    private function resolveTransactionForDeletion(User $user, $transactionId): ?Transaction
    {
        if (is_numeric($transactionId)) {
            Log::info('Buscando transação por ID', [
                'id' => $transactionId,
            ]);

            return Transaction::query()
                ->with('category')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->first();
        }

        $query = mb_strtolower(trim((string) $transactionId));
        $query = str_replace(['*', '_'], '', $query);

        if ($query === '' || in_array($query, ['ultima', 'última', 'ultima transacao', 'última transação', 'ultima compra', 'última compra'], true)) {
            return Transaction::query()
                ->with('category')
                ->where('user_id', $user->id)
                ->latest('date')
                ->latest('id')
                ->first();
        }

        Log::info('Buscando transação por descrição/valor', [
            'busca' => $query,
        ]);

        $candidates = Transaction::query()
            ->with('category')
            ->where('user_id', $user->id)
            ->where(function ($builder) use ($query) {
                $builder->whereRaw('LOWER(description) LIKE ?', ['%'.$query.'%'])
                    ->orWhereHas('category', function ($categoryQuery) use ($query) {
                        $categoryQuery->whereRaw('LOWER(name) LIKE ?', ['%'.$query.'%']);
                    });
            })
            ->latest('date')
            ->latest('id')
            ->limit(5)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() > 1) {
            throw new \Exception('Encontrei mais de uma transação com esse contexto. Me diga o valor ou a data da que você quer apagar.');
        }

        return $candidates->first();
    }

    /**
     * Monta uma resposta de exclusão mais humana.
     */
    private function buildDeleteReply(array $transaction): string
    {
        $amount = number_format((float) ($transaction['amount'] ?? 0), 2, ',', '.');
        $description = trim((string) ($transaction['description'] ?? ''));
        $category = trim((string) ($transaction['category_name'] ?? ''));
        $label = $description !== '' && ! $this->isPlaceholderDescription($description)
            ? $description
            : ($category !== '' ? $category : 'transação');

        return "✅ Apaguei {$label} de R$ {$amount}.";
    }

    /**
     * Obtém o JID do destinatário para envio de mensagem
     */
    private function getRecipientJid(PhoneNumberService $phoneNumberService): string
    {
        // Prioridade 1: JID original (garante entrega para @lid ou @s.whatsapp.net exato)
        if ($this->remoteJid) {
            return $this->remoteJid;
        }

        // Prioridade 2: Número real do usuário (convertido para JID com prefixo 55 se necessário)
        $cleanNumber = $phoneNumberService->clean($this->phoneNumber);

        return $phoneNumberService->toWhatsAppJid($cleanNumber);
    }

    /**
     * Envia mensagem de erro ao usuário
     */
    private function sendErrorMessage(
        BaileysService $baileysService,
        PhoneNumberService $phoneNumberService,
        string $message
    ): void {
        try {
            $recipientJid = $this->getRecipientJid($phoneNumberService);
            $baileysService->sendTextMessage($recipientJid, $message);
        } catch (\Exception $sendError) {
            Log::error('Erro ao enviar mensagem de erro', [
                'error' => $sendError->getMessage(),
                'original_error' => $message,
            ]);
        }
    }

    /**
     * Gera mensagem de erro amigável baseada na exceção
     */
    private function getErrorMessage(\Exception $e): string
    {
        $errorType = get_class($e);

        // Mensagens específicas por tipo de erro
        $messages = [
            \Illuminate\Validation\ValidationException::class => "❌ Não consegui processar sua mensagem. Verifique se o formato está correto.\n\n".
                "Exemplos:\n".
                "• Gastei 50 reais no supermercado\n".
                "• Recebi 1000 de salário\n".
                '• Qual é o meu saldo?',

            \Illuminate\Database\QueryException::class => '❌ Ocorreu um erro ao salvar os dados. Tente novamente em alguns instantes.',
        ];

        // Retorna mensagem específica ou mensagem padrão
        return $messages[$errorType] ??
            "❌ Desculpe, ocorreu um erro ao processar sua mensagem. Tente novamente em alguns instantes.\n\n".
            'Se o problema persistir, entre em contato com o suporte.';
    }

    /**
     * Gera e envia relatório via WhatsApp
     */
    private function generateAndSendReport(
        User $user,
        string $action,
        BaileysService $baileysService,
        PhoneNumberService $phoneNumberService
    ): void {
        try {
            $period = 'monthly'; // Por padrão, mês atual
            $selectedMonth = now()->format('Y-m');
            $year = now()->year;

            // Extrai período da mensagem se mencionado
            if (preg_match('/\b(ano|anual|yearly)\b/i', $this->message)) {
                $period = 'yearly';
            }

            // Extrai mês/ano específico se mencionado
            if (preg_match('/\b(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s+(\d{4})\b/i', $this->message, $matches)) {
                $monthNames = [
                    'janeiro' => '01', 'fevereiro' => '02', 'março' => '03', 'abril' => '04',
                    'maio' => '05', 'junho' => '06', 'julho' => '07', 'agosto' => '08',
                    'setembro' => '09', 'outubro' => '10', 'novembro' => '11', 'dezembro' => '12',
                ];
                $month = $monthNames[strtolower($matches[1])] ?? null;
                $year = (int) $matches[2];
                if ($month) {
                    $selectedMonth = "{$year}-{$month}";
                }
            }

            // Determina formato do relatório
            $format = match ($action) {
                'query_report_pdf' => 'pdf',
                'query_report_csv' => 'csv',
                'query_report_excel' => 'excel',
                default => 'pdf',
            };

            // Gera URL do relatório
            $reportUrl = match ($format) {
                'pdf' => route('reports.export.pdf', [
                    'period' => $period,
                    'selectedMonth' => $selectedMonth,
                    'year' => $year,
                ]),
                'excel' => route('reports.export.excel', [
                    'period' => $period,
                    'selectedMonth' => $selectedMonth,
                    'year' => $year,
                ]),
                'csv' => route('transactions.export.csv', [
                    'period' => $period,
                    'selectedMonth' => $selectedMonth,
                    'year' => $year,
                ]),
                default => route('reports.export.pdf', [
                    'period' => $period,
                    'selectedMonth' => $selectedMonth,
                    'year' => $year,
                ]),
            };

            $formatName = match ($format) {
                'pdf' => 'PDF',
                'csv' => 'CSV',
                'excel' => 'Excel',
                default => 'PDF',
            };

            $periodName = $period === 'monthly'
                ? 'mês atual'
                : "ano de {$year}";

            if ($selectedMonth !== now()->format('Y-m') && $period === 'monthly') {
                $periodName = 'mês '.\Carbon\Carbon::createFromFormat('Y-m', $selectedMonth)->translatedFormat('m/Y');
            }

            $message = "📊 Seu relatório {$formatName} está pronto.\n\n".
                "📅 Período: {$periodName}\n".
                "🔗 Link para abrir ou baixar:\n{$reportUrl}\n\n".
                'Se quiser, eu também posso gerar esse relatório em outro formato.';

            $this->sendResponse($baileysService, $phoneNumberService, $message, $user);

            Log::info('Relatório gerado via WhatsApp', [
                'user_id' => $user->id,
                'format' => $format,
                'period' => $period,
                'selectedMonth' => $selectedMonth,
                'year' => $year,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório via WhatsApp', [
                'user_id' => $user->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            $errorMessage = '❌ Não consegui gerar o relatório. Tente novamente em alguns instantes.';
            $this->sendErrorMessage($baileysService, $phoneNumberService, $errorMessage);
        }
    }
}
