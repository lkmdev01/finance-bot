<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\PhoneNumberService;
use App\Services\PerformanceMetricsService;
use App\Services\WhatsApp\ActionHandlerFactory;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\ConversationTelemetryService;
use App\Services\WhatsApp\ProactiveConversationTrigger;
use App\Services\WhatsAppFormatter;
use App\Services\WhatsAppMessageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    private ?string $finalReply = null;

    public function __construct(
        public readonly string $phoneNumber,
        public readonly string $message,
        public readonly int $userId,
        public readonly ?string $pushName = null,
        public readonly ?string $remoteJid = null,
        public readonly ?string $imageUrl = null,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->userId))->releaseAfter(30)];
    }

    public function rememberFinalReply(string $reply): void
    {
        $this->finalReply = $this->sanitizeUtf8($reply);
    }

    public function getFinalReply(): ?string
    {
        return $this->finalReply;
    }

    public function sendResponse(
        BaileysService $baileysService,
        PhoneNumberService $phoneNumberService,
        string $message,
        User $user
    ): void {
        $recipientJid = $this->getRecipientJid($phoneNumberService);
        $message = $this->sanitizeUtf8($message);

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
                    'message_length' => mb_strlen($message),
                    'message_preview' => substr($message, 0, 100),
                ]);
            }
        } catch (\Exception $sendError) {
            Log::error('Excecao ao enviar mensagem via WhatsApp', [
                'error' => $sendError->getMessage(),
                'recipient' => $recipientJid,
                'user_id' => $user->id,
            ]);
        }
    }

    public function handle(
        AIService $aiService,
        BaileysService $baileysService,
        PhoneNumberService $phoneNumberService,
        PerformanceMetricsService $metricsService,
        ?WhatsAppMessageProcessor $processor = null,
        ?ActionHandlerFactory $handlerFactory = null,
    ): void {
        $processor ??= app(WhatsAppMessageProcessor::class);
        $handlerFactory ??= app(ActionHandlerFactory::class);

        $contact = null;
        $telemetry = null;

        try {
            $this->finalReply = null;
            $user = User::findOrFail($this->userId);
            $telemetry = app(ConversationTelemetryService::class);

            $contact = WhatsAppContact::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'phone_number' => $this->phoneNumber,
                ],
                [
                    'name' => $this->pushName,
                    'context' => [],
                    'conversation_state' => [],
                ]
            );

            if ($this->pushName && ! $contact->name) {
                $contact->update(['name' => $this->pushName]);
            }

            $stateService = app(ConversationStateService::class);
            $orchestrator = app(ConversationOrchestrator::class);
            $proactiveTrigger = app(ProactiveConversationTrigger::class);

            $preflight = $orchestrator->beforeAI($this->message, $user, $contact);

            Log::info('WhatsApp preflight processado', [
                'user_id' => $user->id,
                'message' => mb_substr($this->message, 0, 160),
                'handled' => $preflight['handled'] ?? false,
                'action' => $preflight['action'] ?? ($preflight['result']['action'] ?? null),
                'classification' => $preflight['classification'] ?? null,
            ]);

            if (($preflight['handled'] ?? false) === true) {
                $reply = $preflight['reply'] ?? '';
                $this->rememberFinalReply($reply);
                $this->sendResponse($baileysService, $phoneNumberService, $reply, $user);
                $stateService->applyHandledResult($contact, $this->message, $preflight['action'] ?? null, $reply, $preflight['metadata'] ?? []);
                $proactiveTrigger->dispatch($user, $contact, $preflight['action'] ?? null, $preflight, $this);
                $telemetry->record($user, $contact, $this->message, [
                    'classification' => $preflight['classification'] ?? null,
                    'action' => $preflight['action'] ?? null,
                    'handler' => 'preflight',
                    'used_ai' => false,
                    'status' => 'handled_preflight',
                    'reply' => $reply,
                    'metadata' => [
                        'preflight_handled' => true,
                    ],
                ]);
                return;
            }

            if (isset($preflight['result'])) {
                $result = $preflight['result'];
            } else {
                $startTime = microtime(true);
                $result = $processor->process($this->message, $user, $contact);
                $processingTime = round((microtime(true) - $startTime) * 1000, 2);
                $metricsService->recordAITime($processingTime, $result['action'] ?? null);

                Log::info('WhatsApp resposta da IA recebida', [
                    'user_id' => $user->id,
                    'action' => $result['action'] ?? null,
                    'has_transaction_data' => ! empty($result['transaction_data'] ?? []),
                    'has_goal_data' => ! empty($result['goal_data'] ?? []),
                    'has_subscription_data' => ! empty($result['subscription_data'] ?? []),
                ]);
            }

            $action = $result['action'] ?? null;

            if ($action === null && $this->looksLikeBudgetCreateIntent()) {
                $inferredBudgetData = $this->inferBudgetDataFromMessage();

                if ($inferredBudgetData !== null) {
                    $action = 'create_budget';
                    $result['action'] = 'create_budget';
                    $result['budget_data'] = array_merge($result['budget_data'] ?? [], $inferredBudgetData);
                }
            }

            $handled = $handlerFactory->process($action, $result, $user, $contact, $this);

            Log::info('WhatsApp pos-handler', [
                'user_id' => $user->id,
                'action' => $action,
                'handled' => $handled,
                'handler' => $result['_selected_handler'] ?? null,
                'reply_preview' => mb_substr((string) ($this->getFinalReply() ?? $result['reply'] ?? ''), 0, 120),
            ]);

            if ($handled) {
                $reply = $this->getFinalReply() ?? ($result['reply'] ?? '');
                if ($reply !== '') {
                    $metadata = $orchestrator->metadataForResult($this->message, $action, $result, $contact);
                    $stateService->applyHandledResult($contact, $this->message, $action, $reply, $metadata);
                    $proactiveTrigger->dispatch($user, $contact, $action, $result, $this);
                }

                $telemetry->record($user, $contact, $this->message, [
                    'classification' => $preflight['classification'] ?? null,
                    'action' => $action,
                    'handler' => $result['_selected_handler'] ?? null,
                    'used_ai' => ! isset($preflight['result']),
                    'status' => 'handled',
                    'reply' => $reply,
                    'metadata' => [
                        'preflight_handled' => false,
                        'reply_kind' => $result['_conversation_metadata']['reply_kind'] ?? null,
                    ],
                ]);
                return;
            }

            $formattedReply = WhatsAppFormatter::format($result['reply'] ?? '');
            $this->rememberFinalReply($formattedReply);
            $this->sendResponse($baileysService, $phoneNumberService, $formattedReply, $user);

            $metadata = $orchestrator->metadataForResult($this->message, $action, $result, $contact);
            $stateService->applyHandledResult($contact, $this->message, $action, $formattedReply, $metadata);
            $proactiveTrigger->dispatch($user, $contact, $action, $result, $this);
            $telemetry->record($user, $contact, $this->message, [
                'classification' => $preflight['classification'] ?? null,
                'action' => $action,
                'handler' => $result['_selected_handler'] ?? null,
                'used_ai' => ! isset($preflight['result']),
                'status' => 'fallback_reply',
                'reply' => $formattedReply,
                'metadata' => [
                    'preflight_handled' => false,
                ],
            ]);
        } catch (\Exception $e) {
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
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);

            $errorMessage = $this->getErrorMessage($e);
            $this->rememberFinalReply($errorMessage);
            $this->sendErrorMessage($baileysService, $phoneNumberService, $errorMessage);

            if ($contact) {
                app(ConversationStateService::class)->applyHandledResult($contact, $this->message, null, $errorMessage, [
                    'clear_pending' => false,
                    'reply_kind' => 'error',
                ]);
            }

            if (isset($user) && $telemetry instanceof ConversationTelemetryService) {
                $telemetry->record($user, $contact, $this->message, [
                    'classification' => $preflight['classification'] ?? null,
                    'action' => $action ?? null,
                    'handler' => $result['_selected_handler'] ?? null,
                    'used_ai' => isset($result) && ! isset($preflight['result']),
                    'status' => 'error',
                    'reply' => $errorMessage,
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'metadata' => [
                        'line' => $e->getLine(),
                        'file' => $e->getFile(),
                    ],
                ]);
            }
        }
    }

    private function looksLikeBudgetCreateIntent(): bool
    {
        $message = mb_strtolower($this->message);

        if (! str_contains($message, 'orcamento') && ! str_contains($message, 'orçamento')) {
            return false;
        }

        foreach (['criar', 'crie', 'definir', 'defina', 'cadastrar', 'cadastre', 'adicionar', 'adicione', 'registrar', 'registre', 'ajustar', 'ajuste', 'colocar', 'coloque'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function inferBudgetDataFromMessage(): ?array
    {
        if (! preg_match('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $this->message, $amountMatches)) {
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
        preg_match_all('/(?:r\$\s*)?\d+(?:[.,]\d{1,2})?/u', $message, $amountMatches);
        $amountCount = count($amountMatches[0] ?? []);

        if ($amountCount < 2) {
            return false;
        }

        $connectors = [' e ', ',', ';', "\n", ' depois ', ' também ', ' tambem ', ' mais '];

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
        return "Atencao: eu ainda nao consigo registrar varios lancamentos na mesma mensagem.\n\n"
            ."Manda um por vez, assim:\n"
            ."- Gastei 32 no Uber\n"
            ."- Gastei 48 no mercado\n"
            ."- Recebi 420 de freelance\n\n"
            ."Se quiser, pode me enviar uma mensagem atras da outra que eu registro tudo.";
    }

    private function buildValidationGuidanceReply(array $errors = []): string
    {
        $message = mb_strtolower($this->message);

        if ($this->isCompoundFinancialMessage()) {
            return $this->buildCompoundTransactionReply();
        }

        if (str_contains($message, 'apaga') || str_contains($message, 'apagar') || str_contains($message, 'exclui') || str_contains($message, 'remove')) {
            return "Atencao: nao consegui entender qual transacao voce quer apagar.\n\n"
                ."Tente assim:\n"
                ."- apagar ultima transacao\n"
                ."- apagar Uber de 18 reais\n"
                ."- apagar mercado de ontem";
        }

        if (str_contains($message, 'relatorio') || str_contains($message, 'relatório')) {
            return "Atencao: nao consegui entender qual relatorio voce quer gerar.\n\n"
                ."Tente assim:\n"
                ."- me gera um relatorio do mes\n"
                ."- me manda o relatorio em PDF\n"
                ."- relatorio anual em Excel";
        }

        if (str_contains($message, 'saldo') || str_contains($message, 'gastos') || str_contains($message, 'receitas') || str_contains($message, 'ultimos') || str_contains($message, 'últimos')) {
            return "Atencao: nao consegui entender essa consulta do jeito que ela veio.\n\n"
                ."Voce pode tentar assim:\n"
                ."- qual e o meu saldo?\n"
                ."- quais foram meus ultimos gastos?\n"
                ."- quanto eu gastei esse mes?";
        }

        $details = $this->sanitizeUtf8(collect($errors)->filter()->implode(' '));
        $base = "Atencao: nao consegui entender essa mensagem do jeito que ela veio.\n\n"
            ."Tente mandar em um destes formatos:\n"
            ."- Gastei 50 no supermercado\n"
            ."- Recebi 1000 de salario\n"
            ."- Qual e o meu saldo?";

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
            $baileysService->sendTextMessage($recipientJid, $this->sanitizeUtf8($message));
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

            if (str_contains($errorMessage, 'mais de uma transacao') || str_contains($errorMessage, 'mais de uma transação')) {
                return 'Atencao: '.$this->sanitizeUtf8($errorMessage);
            }

            if (
                str_contains($errorMessage, 'Nao consegui identificar qual transacao deletar')
                || str_contains($errorMessage, 'Não consegui identificar qual transação deletar')
                || str_contains($errorMessage, 'Transacao nao encontrada para exclusao')
                || str_contains($errorMessage, 'Transação não encontrada para exclusão')
            ) {
                return 'Atencao: '.$this->sanitizeUtf8($errorMessage);
            }
        }

        $messages = [
            \Illuminate\Validation\ValidationException::class => $this->buildValidationGuidanceReply(),
            \Illuminate\Database\QueryException::class => 'Erro ao salvar os dados. Tente novamente em alguns instantes.',
        ];

        return $messages[$errorType]
            ?? "Desculpe, ocorreu um erro ao processar sua mensagem. Tente novamente em alguns instantes.\n\nSe o problema persistir, entre em contato com o suporte.";
    }

    private function sanitizeUtf8(string $value): string
    {
        $value = WhatsAppFormatter::normalizeTextEncoding($value);
        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($sanitized === false) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $sanitized;
    }
}

