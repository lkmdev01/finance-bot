<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $provider, // 'gemini', 'ollama', 'groq', 'openai'
        private readonly AIContextBuilder $contextBuilder,
        private readonly AIPromptBuilder $promptBuilder,
        private readonly AIResponseParser $responseParser,
    ) {}

    /**
     * Processa a mensagem do usuário e retorna uma resposta com ação
     */
    public function processMessage(string $message, User $user, ?WhatsAppContact $contact = null): array
    {
        // Constrói contexto usando AIContextBuilder
        $context = $this->contextBuilder->build($user, $contact);

        // Normaliza a mensagem (pode usar contexto se necessário no futuro)
        $normalizedMessage = $this->normalizeMessage($message, $context['contact_context'] ?? null);

        // Constrói prompt usando AIPromptBuilder
        $prompt = $this->promptBuilder->build($normalizedMessage, $context);

        // Chama IA
        $response = $this->callAI($prompt);

        // Parseia resposta usando AIResponseParser
        $parsedResponse = $this->responseParser->parse($response);

        // Fallback se o parser falhar
        if (!isset($parsedResponse['reply'])) {
            Log::warning('AI response parser failed to extract reply', [
                'raw_response' => substr($response, 0, 500),
                'parsed' => $parsedResponse,
            ]);

            return [
                'reply' => '❌ Desculpe, ocorreu um erro ao processar sua mensagem.',
                'action' => null,
                'transaction_data' => null,
                'transaction_id' => null,
            ];
        }

        return [
            'reply' => $parsedResponse['reply'],
            'action' => $parsedResponse['action'] ?? null,
            'transaction_data' => $parsedResponse['transaction_data'] ?? null,
        ];
    }

    /**
     * Normaliza a mensagem para melhor reconhecimento pela IA
     */
    private function normalizeMessage(string $message, ?array $previousContext = null): string
    {
        // Sanitiza mensagem primeiro
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $message = trim($message);
        $message = mb_substr($message, 0, 1000); // Limita tamanho

        // Remove espaços extras
        $message = preg_replace('/\s+/', ' ', $message);

        // Verifica se é uma confirmação e se há contexto anterior
        $isConfirmation = preg_match('/\b(pode|sim|ok|tudo bem|verifica|verificar|pode verificar|claro|pode sim)\b/i', $message);

        // Se for uma confirmação e houver contexto anterior com pergunta,
        // a IA deve usar o histórico (isso será tratado no prompt)

        // Normaliza variações comuns de valores monetários
        $message = preg_replace('/\b(\d+)\s*(reais?|R\$?)\b/i', '$1 reais', $message);
        $message = preg_replace('/\bR\$\s*(\d+(?:[.,]\d+)?)\b/i', 'R$ $1', $message);

        // Normaliza expressões comuns
        $replacements = [
            '/\bperdi tanto\b/i' => 'perdi',
            '/\bgastei tanto\b/i' => 'gastei',
            '/\bquanto tenho\b/i' => 'quanto tenho disponível',
            '/\bquanto sobrou\b/i' => 'quanto tenho disponível',
            '/\bquanto falta\b/i' => 'quanto tenho disponível',
            '/\bcaiu na conta\b/i' => 'entrou',
            '/\bfoi embora\b/i' => 'gastei',
            '/\bsaiu do bolso\b/i' => 'gastei',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message);
        }

        return $message;
    }

    /**
     * Chama a API de IA
     */
    private function callAI(string $prompt): string
    {
        return match ($this->provider) {
            'gemini' => $this->callGemini($prompt),
            'ollama' => $this->callOllama($prompt),
            'groq' => $this->callGroq($prompt),
            'openai' => $this->callOpenAI($prompt),
            default => throw new \InvalidArgumentException("Provider '{$this->provider}' não suportado"),
        };
    }

    /**
     * Chama a API do Google Gemini (gratuita com limites)
     */
    private function callGemini(string $prompt): string
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(30)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key={$this->apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.8,
                'maxOutputTokens' => 2048,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        throw new \RuntimeException('Erro ao chamar Gemini API: '.$response->body());
    }

    /**
     * Chama a API do Ollama (local, 100% gratuito)
     */
    private function callOllama(string $prompt): string
    {
        $baseUrl = config('ai.ollama.base_url');
        $model = config('ai.ollama.model');

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(60)->post("{$baseUrl}/api/generate", [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.8,
                'num_predict' => 2048,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();

            return $data['response'] ?? '';
        }

        throw new \RuntimeException('Erro ao chamar Ollama API: '.$response->body());
    }

    /**
     * Chama a API do Groq com retry para rate limit
     */
    private function callGroq(string $prompt): string
    {
        $model = config('ai.groq.model');
        $maxRetries = 5;
        $retryDelay = 2; // base em segundos

        for ($i = 0; $i < $maxRetries; $i++) {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.8,
                'max_tokens' => 2048,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['choices'][0]['message']['content'] ?? '';
            }

            // Se for rate limit (429), aguarda com backoff exponencial
            if ($response->status() === 429 && $i < $maxRetries - 1) {
                $wait = $retryDelay * pow(2, $i);
                Log::warning("Groq Rate Limit atingido. Tentativa ".($i+1)."/$maxRetries. Aguardando {$wait}s...");
                sleep($wait);
                continue;
            }

            Log::error('Erro ao chamar Groq API', [
                'status' => $response->status(),
                'body' => $response->body(),
                'attempt' => $i + 1
            ]);

            throw new \RuntimeException('Erro ao chamar Groq API: '.$response->body());
        }

        throw new \RuntimeException('Erro ao chamar Groq API após ' . $maxRetries . ' tentativas');
    }

    /**
     * Chama a API do OpenAI (pago após créditos gratuitos)
     */
    private function callOpenAI(string $prompt): string
    {
        $model = config('ai.openai.model');

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.8,
            'max_tokens' => 2048,
        ]);

        if ($response->successful()) {
            $data = $response->json();

            return $data['choices'][0]['message']['content'] ?? '';
        }

        throw new \RuntimeException('Erro ao chamar OpenAI API: '.$response->body());
    }
}
