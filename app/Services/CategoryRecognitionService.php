<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;

class CategoryRecognitionService
{
    protected array $keywords = [
        'alimentação' => ['supermercado', 'padaria', 'restaurante', 'lanche', 'comida', 'mercado', 'ifood', 'rappi', 'uber eats', 'pizza', 'hambúrguer', 'burger', 'açougue', 'bebida', 'café', 'starbucks', 'mcdonalds', 'bk', 'burger king'],
        'transporte' => ['uber', 'taxi', 'combustível', 'gasolina', 'estacionamento', 'pedágio', 'metro', 'ônibus', '99app', '99pop', 'postel', 'shell', 'ipiranga', 'br', 'mecanico', 'oficina'],
        'saúde' => ['farmacia', 'farmácia', 'médico', 'hospital', 'clínica', 'exame', 'plano de saúde', 'dentista', 'psicologo', 'droga raia', 'drogasil', 'pague menos'],
        'educação' => ['escola', 'faculdade', 'curso', 'livro', 'material escolar', 'udemy', 'alura', 'ingles', 'mensalidade escolar'],
        'lazer' => ['cinema', 'show', 'festival', 'viagem', 'hotel', 'passagem', 'netflix', 'spotify', 'disney+', 'prime video', 'hbo', 'ingressos', 'bar', 'churrasco'],
        'casa' => ['aluguel', 'condomínio', 'luz', 'água', 'internet', 'telefone', 'gás', 'enel', 'sabesp', 'claro', 'vivo', 'tim', 'oi', 'iptu'],
        'compras' => ['amazon', 'magazine luiza', 'americanas', 'casas bahia', 'mercado livre', 'shopee', 'shein', 'aliexpress', 'loja', 'vestuario', 'roupa', 'sapato'],
        'salário' => ['salário', 'pagamento', 'folha', 'proventos', 'pix recebido', 'transferencia recebida'],
        'pets' => ['petshop', 'veterinário', 'ração', 'banho e tosa', 'caopanheiro'],
    ];

    public function recognizeCategory(User $user, string $description, ?float $amount = null): ?Category
    {
        $description = mb_strtolower(Str::ascii($description));

        // Buscar por palavras-chave
        foreach ($this->keywords as $categoryName => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($description, $keyword)) {
                    $category = $this->findOrCreateCategory($user, $categoryName, $this->getCategoryType($categoryName));

                    return $category;
                }
            }
        }

        // Buscar por histórico do usuário
        $similarTransaction = $user->transactions()
            ->where('description', 'like', "%{$description}%")
            ->whereNotNull('category_id')
            ->first();

        if ($similarTransaction && $similarTransaction->category) {
            return $similarTransaction->category;
        }

        return null;
    }

    public function findOrCreateCategory(User $user, string $name, string $type): Category
    {
        $categoryName = ucfirst(mb_strtolower($name));
        
        return $user->categories()->firstOrCreate(
            [
                'name' => $categoryName,
                'type' => $type,
            ],
            [
                'color' => $this->getCategoryColor($name),
                'icon' => $this->getCategoryIcon($name),
            ]
        );
    }

    protected function getCategoryType(string $categoryName): string
    {
        return $categoryName === 'salário' ? 'income' : 'expense';
    }

    protected function getCategoryColor(string $categoryName): string
    {
        return match ($categoryName) {
            'alimentação' => '#FF5733',
            'transporte' => '#3498DB',
            'saúde' => '#E74C3C',
            'educação' => '#9B59B6',
            'lazer' => '#F39C12',
            'casa' => '#16A085',
            'compras' => '#E67E22',
            'salário' => '#27AE60',
            'pets' => '#795548',
            default => '#95A5A6',
        };
    }

    protected function getCategoryIcon(string $categoryName): string
    {
        return match ($categoryName) {
            'alimentação' => '🍔',
            'transporte' => '🚗',
            'saúde' => '🏥',
            'educação' => '📚',
            'lazer' => '🎬',
            'casa' => '🏠',
            'compras' => '🛒',
            'salário' => '💰',
            'pets' => '🐶',
            default => '📦',
        };
    }
}
