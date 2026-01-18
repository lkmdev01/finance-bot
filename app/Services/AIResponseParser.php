<?php

namespace App\Services;

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
                // Se o campo 'reply' ainda parecer ser um JSON (devido a nesting ou escaping da IA), tenta unwrap
                if (isset($json['reply']) && is_string($json['reply']) && str_starts_with(trim($json['reply']), '{')) {
                    $innerJson = json_decode($json['reply'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return array_merge($json, $innerJson);
                    }
                }
                // Corrige transaction_id se vier como string tipo 'ID 10'
                if (isset($json['transaction_id']) && is_string($json['transaction_id'])) {
                    if (preg_match('/(\d+)/', $json['transaction_id'], $matches)) {
                        $json['transaction_id'] = (int)$matches[1];
                    }
                }
                // Fallback: se não houver reply, mas houver action conhecida, gera resposta amigável
                if (!isset($json['reply'])) {
                    if (($json['action'] ?? null) === 'delete_transaction') {
                        $json['reply'] = 'Transação apagada com sucesso!';
                    }
                    // Outros fallbacks podem ser adicionados aqui
                }
                return $json;
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
