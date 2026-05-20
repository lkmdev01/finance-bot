<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\BillingPlanService;
use App\Services\CategoryRecognitionService;
use App\Services\PhoneNumberService;
use App\Services\PerformanceMetricsService;
use App\Services\WhatsAppFormatter;
use App\Services\WhatsAppMessageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->userId))->releaseAfter(30)];
    }

    /**
     * Envia resposta via WhatsApp
     */
    public function sendResponse(
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
            $billingPlanService = app(BillingPlanService::class);

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

            if ($this->looksLikeBudgetCreateIntent()) {
                $inferredBudgetData = $this->inferBudgetDataFromMessage();

                if ($inferredBudgetData !== null) {
                    $action = 'create_budget';
                    $result['action'] = 'create_budget';
                    $result['transaction_data'] = array_merge($result['transaction_data'] ?? [], $inferredBudgetData);
                }
            }

            // Delega toda a lógica de ação para a Factory de Handlers.
            // Cada handler é responsável por enviar a própria resposta e retornar true.
            $handlerFactory = new \App\Services\WhatsApp\ActionHandlerFactory();
            $handled = $handlerFactory->process($action, $result, $user, $contact, $this);

            if ($handled) {
                return;
            }

            // Nenhum handler reconheceu a ação — envia o reply da IA diretamente (fallback)
            $formattedReply = WhatsAppFormatter::format($result['reply'] ?? '');
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

    private function looksLikeBudgetCreateIntent(): bool
    {
        $message = mb_strtolower($this->message);

        if (! str_contains($message, 'orcamento') && ! str_contains($message, 'orçamento')) {
            return false;
        }

        foreach (['criar', 'crie', 'definir', 'defina', 'cadastrar', 'cadastre', 'adicionar', 'adicione'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function inferBudgetDataFromMessage(): ?array
    {
        if (! preg_match('/(?:r\\$\\s*)?(\\d+(?:[\\.,]\\d{1,2})?)/u', $this->message, $amountMatches)) {
            return null;
        }

        $rawAmount = str_replace('.', '', $amountMatches[1]);
        $amount = (float) str_replace(',', '.', $rawAmount);

        if ($amount <= 0) {
            return null;
        }

        [$period, $year, $month] = $this->extractBudgetPeriodFromMessage();

        $categoryName = null;
        if (preg_match('/(?:para|pra)\s+(.+)$/iu', $this->message, $categoryMatches)) {
            $categoryName = trim($categoryMatches[1]);
        }

        if ($categoryName === null || $categoryName === '') {
            return null;
        }

        return [
            'amount' => $amount,
            'period' => $period,
            'year' => $year,
            'month' => $month,
            'category_name' => $categoryName,
        ];
    }

    private function extractBudgetPeriodFromMessage(): array
    {
        $message = mb_strtolower($this->message);
        $year = now()->year;
        $month = now()->month;
        $period = 'monthly';

        if (preg_match('/\b(anual|ano)\b/u', $message)) {
            $period = 'yearly';
            $month = null;
        }

        if (preg_match('/\b(20\d{2})\b/u', $message, $yearMatches)) {
            $year = (int) $yearMatches[1];
        }

        $months = [
            'janeiro' => 1,
            'fevereiro' => 2,
            'marco' => 3,
            'março' => 3,
            'abril' => 4,
            'maio' => 5,
            'junho' => 6,
            'julho' => 7,
            'agosto' => 8,
            'setembro' => 9,
            'outubro' => 10,
            'novembro' => 11,
            'dezembro' => 12,
        ];

        foreach ($months as $name => $number) {
            if (str_contains($message, $name)) {
                $period = 'monthly';
                $month = $number;
                break;
            }
        }

        return [$period, $year, $month];
    }

    private function isCompoundFinancialMessage(): bool
    {
        $message = mb_strtolower(trim($this->message));

        if (! $this->looksLikeTransactionIntent($message)) {
            return false;
        }

        $amountMatches = [];
        preg_match_all('/(?:r\\$\\s*)?\\d+(?:[.,]\\d{1,2})?/u', $message, $amountMatches);
        $amountCount = count($amountMatches[0] ?? []);

        if ($amountCount < 2) {
            return false;
        }

        $connectors = [
            ' e ',
            ',',
            ';',
            "\n",
            ' depois ',
            ' também ',
            ' tambem ',
            ' mais ',
        ];

        foreach ($connectors as $connector) {
            if (str_contains($message, $connector)) {
                return true;
            }
        }

        $verbs = ['gastei', 'paguei', 'recebi', 'ganhei', 'entrou'];
        $verbHits = 0;

        foreach ($verbs as $verb) {
            if (str_contains($message, $verb)) {
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

    private function buildCompoundTransactionReply(): string
    {
        return "⚠️ Eu ainda não consigo registrar vários lançamentos na mesma mensagem.\n\n".
            "Manda um por vez, assim:\n".
            "• Gastei 32 no Uber\n".
            "• Gastei 48 no mercado\n".
            "• Recebi 420 de freelance\n\n".
            "Se quiser, pode me enviar uma mensagem atrás da outra que eu registro tudo.";
    }

    private function buildValidationGuidanceReply(array $errors = []): string
    {
        $message = mb_strtolower($this->message);

        if ($this->isCompoundFinancialMessage()) {
            return $this->buildCompoundTransactionReply();
        }

        if (str_contains($message, 'apaga') || str_contains($message, 'apagar') || str_contains($message, 'exclui') || str_contains($message, 'remove')) {
            return "⚠️ Não consegui entender qual transação você quer apagar.\n\n".
                "Tente assim:\n".
                "• apagar última transação\n".
                "• apagar Uber de 18 reais\n".
                "• apagar mercado de ontem";
        }

        if (str_contains($message, 'relatorio') || str_contains($message, 'relatório')) {
            return "⚠️ Não consegui entender qual relatório você quer gerar.\n\n".
                "Tente assim:\n".
                "• me gera um relatório do mês\n".
                "• me manda o relatório em PDF\n".
                "• relatório anual em Excel";
        }

        if (str_contains($message, 'saldo') || str_contains($message, 'gastos') || str_contains($message, 'receitas') || str_contains($message, 'ultimos') || str_contains($message, 'últimos')) {
            return "⚠️ Não consegui entender essa consulta do jeito que ela veio.\n\n".
                "Você pode tentar assim:\n".
                "• qual é o meu saldo?\n".
                "• quais foram meus últimos gastos?\n".
                "• quanto eu gastei esse mês?";
        }

        $details = collect($errors)
            ->filter()
            ->implode(' ');

        $base = "⚠️ Não consegui entender essa mensagem do jeito que ela veio.\n\n".
            "Tente mandar em um destes formatos:\n".
            "• Gastei 50 no supermercado\n".
            "• Recebi 1000 de salário\n".
            "• Qual é o meu saldo?";

        return $details !== '' ? $base."\n\nDetalhe: {$details}" : $base;
    }

    private function getRecipientJid(PhoneNumberService $phoneNumberService): string
    {
        if ($this->remoteJid) {
            return $this->remoteJid;
        }

        $cleanNumber = $phoneNumberService->clean($this->phoneNumber);

        return $phoneNumberService->toWhatsAppJid($cleanNumber);
    }

    public function sendErrorMessage(
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

    private function getErrorMessage(\Exception $e): string
    {
        $errorType = get_class($e);
        $errorMessage = trim($e->getMessage());

        if ($errorMessage !== '') {
            if ($this->isCompoundFinancialMessage()) {
                return $this->buildCompoundTransactionReply();
            }

            if (str_contains($errorMessage, 'mais de uma transação')) {
                return '⚠️ '.$errorMessage;
            }

            if (
                str_contains($errorMessage, 'Não consegui identificar qual transação deletar')
                || str_contains($errorMessage, 'Transação não encontrada para exclusão')
            ) {
                return '⚠️ '.$errorMessage;
            }
        }

        $messages = [
            \Illuminate\Validation\ValidationException::class => $this->buildValidationGuidanceReply(),
            \Illuminate\Database\QueryException::class => '❌ Ocorreu um erro ao salvar os dados. Tente novamente em alguns instantes.',
        ];

        return $messages[$errorType] ??
            "❌ Desculpe, ocorreu um erro ao processar sua mensagem. Tente novamente em alguns instantes.\n\n".
            'Se o problema persistir, entre em contato com o suporte.';
    }
}
