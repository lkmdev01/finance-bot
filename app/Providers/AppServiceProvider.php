<?php

namespace App\Providers;

use App\Services\AIContextBuilder;
use App\Services\AIPromptBuilder;
use App\Services\AIResponseParser;
use App\Services\AIService;
use App\Services\BaileysService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BaileysService::class, function ($app) {
            return new BaileysService(
                baseUrl: config('whatsapp.baileys.base_url'),
                webhookSecret: config('whatsapp.baileys.webhook_secret'),
            );
        });

        $this->app->singleton(AIService::class, function ($app) {
            // Para Ollama, a API key não é necessária
            $apiKey = config('ai.provider') === 'ollama' ? '' : config('ai.api_key');

            return new AIService(
                apiKey: $apiKey,
                provider: config('ai.provider'),
                contextBuilder: $app->make(AIContextBuilder::class),
                promptBuilder: $app->make(AIPromptBuilder::class),
                responseParser: $app->make(AIResponseParser::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}
