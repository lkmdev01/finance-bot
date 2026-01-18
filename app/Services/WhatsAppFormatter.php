<?php

namespace App\Services;

class WhatsAppFormatter
{
    /**
     * Formata mensagem com negrito, itálico e outros estilos do WhatsApp
     * 
     * WhatsApp suporta:
     * - *texto* para negrito
     * - _texto_ para itálico
     * - ~texto~ para riscado
     * - ```texto``` para monoespaçado
     */
    public static function format(string $message): string
    {
        // Remove formatação existente para evitar duplicação
        $message = self::removeFormatting($message);
        
        return $message;
    }
    
    /**
     * Adiciona negrito ao texto
     */
    public static function bold(string $text): string
    {
        return "*{$text}*";
    }
    
    /**
     * Adiciona itálico ao texto
     */
    public static function italic(string $text): string
    {
        return "_{$text}_";
    }
    
    /**
     * Adiciona riscado ao texto
     */
    public static function strikethrough(string $text): string
    {
        return "~{$text}~";
    }
    
    /**
     * Adiciona monoespaçado ao texto
     */
    public static function monospace(string $text): string
    {
        return "```{$text}```";
    }
    
    /**
     * Formata valor monetário com negrito
     */
    public static function formatMoney(float $amount, bool $bold = true): string
    {
        $formatted = 'R$ ' . number_format($amount, 2, ',', '.');
        return $bold ? self::bold($formatted) : $formatted;
    }
    
    /**
     * Formata título com negrito
     */
    public static function formatTitle(string $title): string
    {
        return self::bold($title);
    }
    
    /**
     * Formata lista de itens
     */
    public static function formatList(array $items, string $prefix = '•'): string
    {
        return implode("\n", array_map(fn($item) => "{$prefix} {$item}", $items));
    }
    
    /**
     * Remove formatação existente
     */
    private static function removeFormatting(string $text): string
    {
        // Remove formatação duplicada ou malformada
        $text = preg_replace('/\*{2,}/', '*', $text);
        $text = preg_replace('/_{2,}/', '_', $text);
        $text = preg_replace('/~{2,}/', '~', $text);
        
        return $text;
    }
    
    /**
     * Formata resposta de saldo
     */
    public static function formatBalance(float $balance): string
    {
        $emoji = $balance >= 0 ? '💰' : '⚠️';
        $status = $balance >= 0 ? 'Saldo disponível' : 'Saldo negativo';
        
        return "{$emoji} " . self::formatTitle($status) . ":\n" . 
               self::formatMoney($balance) . "\n\n" .
               ($balance < 0 ? "⚠️ Atenção: seu saldo está negativo!" : "✅ Tudo certo!");
    }
    
    /**
     * Formata resposta de transação criada
     */
    public static function formatTransactionCreated(array $data): string
    {
        $type = $data['type'] === 'income' ? 'receita' : 'despesa';
        $emoji = $data['type'] === 'income' ? '✅' : '💸';
        
        $message = "{$emoji} " . self::formatTitle("Registrei sua {$type}!") . "\n\n";
        $message .= self::bold("Valor:") . " " . self::formatMoney($data['amount'], false) . "\n";
        
        if (!empty($data['description'])) {
            $message .= self::bold("Descrição:") . " {$data['description']}\n";
        }
        
        if (!empty($data['category'])) {
            $message .= self::bold("Categoria:") . " {$data['category']}\n";
        }
        
        if (!empty($data['date'])) {
            $message .= self::bold("Data:") . " {$data['date']}\n";
        }
        
        return $message;
    }
}
