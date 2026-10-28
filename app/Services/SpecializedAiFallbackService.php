<?php

namespace App\Services;

use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Illuminate\Support\Facades\Log;
use Throwable;

class SpecializedAiFallbackService
{
    public function __construct(private readonly AiUserTokenBudget $budget) {}

    /**
     * Run one of the lightweight intent agents, retrying only provider rate limits
     * with the separately configured fallback provider/model.
     *
     * @return array<string, mixed>
     */
    public function prompt(User $user, Agent $agent, string $prompt, string $agentName): array
    {
        $response = $this->budget->run($user, function () use ($agent, $prompt, $agentName) {
            try {
                return $agent->prompt($prompt);
            } catch (RateLimitedException $primaryException) {
                $fallback = (array) config('ai.specialized_fallback', []);
                $providerName = (string) ($fallback['provider'] ?? '');
                $model = (string) ($fallback['model'] ?? '');
                $provider = Lab::tryFrom($providerName);

                if (! ($fallback['enabled'] ?? false)
                    || $provider === null
                    || $model === ''
                    || blank(config('ai.providers.'.$providerName.'.key'))) {
                    throw $primaryException;
                }

                Log::notice('Tentando modelo de contingência para agente especializado', [
                    'agent' => $agentName,
                    'fallback_provider' => $providerName,
                    'fallback_model' => $model,
                ]);

                try {
                    return $agent->prompt($prompt, provider: $provider, model: $model);
                } catch (Throwable $fallbackException) {
                    Log::warning('Modelo de contingência indisponível para agente especializado', [
                        'agent' => $agentName,
                        'fallback_provider' => $providerName,
                        'fallback_model' => $model,
                        'exception' => $fallbackException::class,
                    ]);

                    throw $fallbackException;
                }
            }
        });

        return $response->toArray();
    }
}
