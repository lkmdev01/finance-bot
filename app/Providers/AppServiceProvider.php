<?php

namespace App\Providers;

use App\Services\AbacatePayService;
use App\Services\AbacatePayWebhookProcessor;
use App\Services\AIContextBuilder;
use App\Services\AIPromptBuilder;
use App\Services\AIResponseParser;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\BillingPlanService;
use App\Services\WhatsApp\ActionHandlerFactory;
use App\Services\WhatsApp\Handlers\CancelSubscriptionHandler;
use App\Services\WhatsApp\Handlers\CancelRecurringTransactionHandler;
use App\Services\WhatsApp\Handlers\CreateBudgetHandler;
use App\Services\WhatsApp\Handlers\CreateCreditCardHandler;
use App\Services\WhatsApp\Handlers\CreateInstallmentTransactionHandler;
use App\Services\WhatsApp\Handlers\CreateRecurringTransactionHandler;
use App\Services\WhatsApp\Handlers\CreateSavingsGoalHandler;
use App\Services\WhatsApp\Handlers\CreateSubscriptionHandler;
use App\Services\WhatsApp\Handlers\CreateTransactionHandler;
use App\Services\WhatsApp\Handlers\DeleteBudgetHandler;
use App\Services\WhatsApp\Handlers\DeleteTransactionHandler;
use App\Services\WhatsApp\Handlers\EditTransactionHandler;
use App\Services\WhatsApp\Handlers\QueryHandler;
use App\Services\WhatsApp\Handlers\ReportHandler;
use App\Services\WhatsApp\Handlers\SplitTransactionHandler;
use App\Services\WhatsApp\Handlers\UpdateBudgetHandler;
use App\Services\WhatsApp\Handlers\UpdateRecurringTransactionHandler;
use App\Services\WhatsApp\Handlers\UpdateSavingsGoalHandler;
use App\Services\WhatsApp\Handlers\UpdateSubscriptionHandler;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AbacatePayService::class, function () {
            return new AbacatePayService(
                baseUrl: config('abacatepay.base_url'),
                legacyBaseUrl: config('abacatepay.legacy_base_url'),
                apiKey: config('abacatepay.api_key'),
                timeout: config('abacatepay.timeout'),
            );
        });

        $this->app->singleton(AbacatePayWebhookProcessor::class, function ($app) {
            return new AbacatePayWebhookProcessor(
                billingPlanService: $app->make(BillingPlanService::class),
            );
        });

        $this->app->singleton(BillingPlanService::class, fn () => new BillingPlanService());

        $this->app->singleton(BaileysService::class, function () {
            return new BaileysService(
                baseUrl: config('whatsapp.baileys.base_url'),
                webhookSecret: config('whatsapp.baileys.webhook_secret'),
            );
        });

        $this->app->singleton(AIService::class, function ($app) {
            // Para Ollama, a API key não é necessária.
            $apiKey = config('ai.provider') === 'ollama' ? '' : config('ai.api_key');

            return new AIService(
                apiKey: $apiKey,
                provider: config('ai.provider'),
                contextBuilder: $app->make(AIContextBuilder::class),
                promptBuilder: $app->make(AIPromptBuilder::class),
                responseParser: $app->make(AIResponseParser::class),
            );
        });

        $this->app->singleton(ActionHandlerFactory::class, function ($app) {
            $handlerClasses = [
                CreateBudgetHandler::class,
                UpdateBudgetHandler::class,
                DeleteBudgetHandler::class,
                CreateSavingsGoalHandler::class,
                UpdateSavingsGoalHandler::class,
                CreateSubscriptionHandler::class,
                CreateCreditCardHandler::class,
                UpdateSubscriptionHandler::class,
                CancelSubscriptionHandler::class,
                CreateRecurringTransactionHandler::class,
                UpdateRecurringTransactionHandler::class,
                CancelRecurringTransactionHandler::class,
                CreateInstallmentTransactionHandler::class,
                CreateTransactionHandler::class,
                EditTransactionHandler::class,
                DeleteTransactionHandler::class,
                SplitTransactionHandler::class,
                QueryHandler::class,
                ReportHandler::class,
            ];

            return new ActionHandlerFactory(
                array_map(static fn (string $handlerClass) => $app->make($handlerClass), $handlerClasses)
            );
        });
    }

    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}
