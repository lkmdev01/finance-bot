<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AIResponseParser
{
    /**
     * Parseia a resposta da IA
     */
    public function parse(string $response): array
    {
        // Procura pelo primeiro '{' e o último '}' para extrair o objeto JSON
        $firstBrace = strpos($response, '{');
        $lastBrace = strrpos($response, '}');

        if ($firstBrace !== false && $lastBrace !== false) {
            $potentialJson = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);

            // Remove quebras de linha reais que invalidam o JSON de strings da IA
            $sanitizedJson = preg_replace('/(?<!\\\\)\R/u', '\n', $potentialJson);
            $json = json_decode($sanitizedJson, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // ... (existing nested JSON logic)
                if (isset($json['reply']) && is_string($json['reply']) && str_starts_with(trim($json['reply']), '{')) {
                    $innerJson = json_decode($json['reply'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return array_merge($json, $innerJson);
                    }
                }
                // ...
                return $json;
            }

            // --- NOVO: Fallback via Regex se o JSON for malformado ---
            Log::debug('JSON malformado detectado, tentando extração via regex', ['response' => $potentialJson]);
            
            $extracted = [];
            
            // Tenta extrair o 'reply' usando um regex que busca o valor entre aspas
            if (preg_match('/"reply"\s*:\s*"(.*?)(?:"\s*,\s*"action"|"\s*,\s*"transaction_data"|"\s*})/s', $potentialJson, $matches)) {
                $extracted['reply'] = $matches[1];
            } elseif (preg_match('/"reply"\s*:\s*"(.*)/s', $potentialJson, $matches)) {
                // Fallback extremo: pega tudo após "reply":" até o fim ou próxima chave
                $val = rtrim($matches[1], ' \t\n\r\0\x0B}');
                $extracted['reply'] = rtrim($val, '", ');
            }

            if (preg_match('/"action"\s*:\s*(?:"(.*?)"|null)/', $potentialJson, $matches)) {
                $extracted['action'] = $matches[1] ?? null;
            }

            if (!empty($extracted['reply'])) {
                // Tira escapes de barra pra exibir texto limpo
                $extracted['reply'] = stripslashes($extracted['reply']);
                return array_merge([
                    'action' => null,
                    'transaction_data' => null,
                ], $extracted);
            }
        }

        // Tenta remover escapes e parsear novamente (caso venha encasulado)
        $unquoted = stripslashes($response);
        if ($unquoted !== $response) {
            return $this->parse($unquoted);
        }

        // Se não conseguir parsear, retorna apenas a resposta
        return [
            'reply' => $response,
            'action' => null,
            'transaction_data' => null,
        ];
    }
}
