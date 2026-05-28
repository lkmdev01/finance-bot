<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;

class CategoryRecognitionService
{
    // Palavras-chave para reconhecimento automático via descrição
    protected array $keywords = [
        'alimentação' => ['supermercado', 'padaria', 'restaurante', 'lanche', 'comida', 'mercado', 'ifood', 'rappi', 'uber eats', 'pizza', 'hambúrguer', 'burger', 'açougue', 'bebida', 'café', 'starbucks', 'mcdonalds', 'bk', 'burger king', 'restaurantes'],
        'transporte' => ['uber', 'taxi', 'táxi', 'combustível', 'gasolina', 'estacionamento', 'pedágio', 'metro', 'ônibus', '99app', '99pop', 'postel', 'shell', 'ipiranga', 'br', 'mecanico', 'oficina', 'passagem'],
        'saúde' => ['farmacia', 'farmácia', 'médico', 'hospital', 'clínica', 'exame', 'plano de saúde', 'dentista', 'psicologo', 'droga raia', 'drogasil', 'pague menos', 'remédio', 'remédios'],
        'educação' => ['escola', 'faculdade', 'curso', 'livro', 'material escolar', 'udemy', 'alura', 'ingles', 'mensalidade escolar', 'facul'],
        'lazer' => ['cinema', 'show', 'festival', 'viagem', 'hotel', 'passagem aérea', 'netflix', 'spotify', 'disney+', 'prime video', 'hbo', 'ingressos', 'bar', 'churrasco', 'cerveja', 'festa'],
        'casa' => ['aluguel', 'condomínio', 'luz', 'água', 'internet', 'telefone', 'gás', 'enel', 'sabesp', 'claro', '固定', 'vivo', 'tim', 'oi', 'iptu', 'móveis', 'reforma'],
        'compras' => ['amazon', 'magazine luiza', 'americanas', 'casas bahia', 'mercado livre', 'shopee', 'shein', 'aliexpress', 'loja', 'vestuario', 'roupa', 'sapato', 'tênis', 'vestuário'],
        'salário' => ['salário', 'pagamento', 'folha', 'proventos', 'pix recebido', 'transferência recebida', 'serviço', 'freela', 'comissão', 'renda'],
        'pets' => ['petshop', 'veterinário', 'ração', 'banho e tosa', 'caopanheiro', 'gato', 'cachorro', 'pet'],
    ];

    /**
     * Mapa de normalização: nomes que a IA pode sugerir → nome canônico da categoria
     * Evita criar duplicatas como "Mercado", "supermercado", "alimentacao" etc.
     */
    protected array $categoryNormalizationMap = [
        // Alimentação
        'mercado'           => 'alimentação',
        'supermercado'      => 'alimentação',
        'alimentacao'       => 'alimentação',
        'alimentacão'       => 'alimentação',
        'comida'            => 'alimentação',
        'refeicao'          => 'alimentação',
        'refeição'          => 'alimentação',
        'lanche'            => 'alimentação',
        'padaria'           => 'alimentação',
        'restaurante'       => 'alimentação',
        'ifood'             => 'alimentação',
        'rappi'             => 'alimentação',
        'açougue'           => 'alimentação',

        // Transporte
        'uber'              => 'transporte',
        'taxi'              => 'transporte',
        'táxi'              => 'transporte',
        'gasolina'          => 'transporte',
        'combustivel'       => 'transporte',
        'combustível'       => 'transporte',
        'passagem'          => 'transporte',
        'onibus'            => 'transporte',
        'ônibus'            => 'transporte',
        '99'                => 'transporte',
        'estacionamento'    => 'transporte',

        // Saúde
        'farmacia'          => 'saúde',
        'farmácia'          => 'saúde',
        'medico'            => 'saúde',
        'médico'            => 'saúde',
        'hospital'          => 'saúde',
        'plano saude'       => 'saúde',
        'dentista'          => 'saúde',
        'remedio'           => 'saúde',

        // Salário / Receita
        'salario'           => 'salário',
        'salário'           => 'salário',
        'renda'             => 'salário',
        'receita'           => 'salário',
        'pagamento'         => 'salário',
        'proventos'         => 'salário',
        'ganhos extras'     => 'salário',
        'servico'           => 'salário',
        'serviço'           => 'salário',

        // Lazer
        'netflix'           => 'lazer',
        'spotify'           => 'lazer',
        'disney'            => 'lazer',
        'cinema'            => 'lazer',
        'show'              => 'lazer',
        'entretenimento'    => 'lazer',
        'streaming'         => 'lazer',
        'jogos'             => 'lazer',
        'viagem'            => 'lazer',

        // Casa
        'aluguel'           => 'casa',
        'condominio'        => 'casa',
        'condomínio'        => 'casa',
        'luz'               => 'casa',
        'agua'              => 'casa',
        'água'              => 'casa',
        'internet'          => 'casa',
        'telefone'          => 'casa',
        'celular'           => 'casa',

        // Compras
        'amazon'            => 'compras',
        'shopee'            => 'compras',
        'roupa'             => 'compras',
        'vestuário'         => 'compras',
        'presente'          => 'compras',

        // Pets
        'pet'               => 'pets',
        'veterinario'       => 'pets',
        'veterinário'       => 'pets',
        'racao'             => 'pets',
        'ração'             => 'pets',
        'petshop'           => 'pets',
    ];

    public function recognizeCategory(User $user, string $description, ?float $amount = null): ?Category
    {
        $normalized = mb_strtolower(Str::ascii($description));

        // Prefer an existing category that matches the raw term before applying canonical normalization.
        // This avoids forcing users into the canonical bucket when they created a specific category
        // like "Supermercado" or "Uber".
        $rawAscii = mb_strtolower(Str::ascii(trim($description)));
        if ($rawAscii !== '') {
            $existingByRaw = $user->categories()
                ->get()
                ->first(fn (Category $category) => mb_strtolower(Str::ascii(trim((string) $category->name))) === $rawAscii);

            if ($existingByRaw instanceof Category) {
                return $existingByRaw;
            }
        }

        // 1. Tenta pelo mapa de normalização
        foreach ($this->categoryNormalizationMap as $synonym => $canonicalName) {
            if (Str::contains($normalized, mb_strtolower(Str::ascii($synonym)))) {
                return $this->findOrCreateCategory($user, $canonicalName, $this->getCategoryType($canonicalName));
            }
        }

        // 2. Busca por palavras-chave
        foreach ($this->keywords as $categoryName => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($normalized, $keyword)) {
                    return $this->findOrCreateCategory($user, $categoryName, $this->getCategoryType($categoryName));
                }
            }
        }

        // 3. Busca por histórico do usuário
        $similarTransaction = $user->transactions()
            ->where('description', 'like', "%{$description}%")
            ->whereNotNull('category_id')
            ->first();

        if ($similarTransaction && $similarTransaction->category) {
            return $similarTransaction->category;
        }

        return null;
    }

    public function findExistingCategoryByName(User $user, string $name, ?string $type = null): ?Category
    {
        $normalizedName = $this->normalizeCategoryName($name);
        $asciiNormalizedName = mb_strtolower(Str::ascii($normalizedName));

        return $user->categories()
            ->get()
            ->first(function (Category $category) use ($type, $normalizedName, $asciiNormalizedName) {
                if ($type && $category->type !== $type) {
                    return false;
                }

                $categoryName = mb_strtolower(trim($category->name));
                $normalizedCategoryName = $this->normalizeCategoryName($categoryName);
                $asciiCategoryName = mb_strtolower(Str::ascii($normalizedCategoryName));

                return $normalizedCategoryName === $normalizedName
                    || $asciiCategoryName === $asciiNormalizedName;
            });
    }

    public function findOrCreateCategory(User $user, string $name, string $type): Category
    {
        // Normaliza o nome antes de criar/buscar
        $normalizedName = $this->normalizeCategoryName($name);
        $categoryName = ucfirst(mb_strtolower($normalizedName));

        return $user->categories()->firstOrCreate(
            [
                'name' => $categoryName,
                'user_id' => $user->id, // Garantindo filtro por usuário
            ],
            [
                'type'  => $type,
                'color' => $this->getCategoryColor($normalizedName),
                'icon'  => $this->getCategoryIcon($normalizedName),
            ]
        );
    }

    /**
     * Normaliza o nome da categoria usando o mapa de sinônimos
     */
    protected function normalizeCategoryName(string $name): string
    {
        $lowerName = mb_strtolower(trim($name));
        $asciiName = mb_strtolower(Str::ascii($lowerName));

        // Verifica se o nome (ou sua versão ASCII) é um sinônimo conhecido
        if (isset($this->categoryNormalizationMap[$lowerName])) {
            return $this->categoryNormalizationMap[$lowerName];
        }

        if (isset($this->categoryNormalizationMap[$asciiName])) {
            return $this->categoryNormalizationMap[$asciiName];
        }

        return $lowerName;
    }

    protected function getCategoryType(string $categoryName): string
    {
        $incomeCategories = ['salário', 'salario', 'renda', 'receita', 'pagamento', 'ganhos extras', 'serviço'];
        return in_array(mb_strtolower($categoryName), $incomeCategories) ? 'income' : 'expense';
    }

    protected function getCategoryColor(string $categoryName): string
    {
        return match (mb_strtolower($categoryName)) {
            'alimentação', 'alimentacao' => '#FF5733',
            'transporte'                 => '#3498DB',
            'saúde', 'saude'             => '#E74C3C',
            'educação', 'educacao'       => '#9B59B6',
            'lazer', 'entretenimento'    => '#F39C12',
            'casa'                       => '#16A085',
            'compras'                    => '#E67E22',
            'salário', 'salario'         => '#27AE60',
            'pets'                       => '#795548',
            default                      => '#95A5A6',
        };
    }

    protected function getCategoryIcon(string $categoryName): string
    {
        return match (mb_strtolower($categoryName)) {
            'alimentação', 'alimentacao' => '🍔',
            'transporte'                 => '🚗',
            'saúde', 'saude'             => '🏥',
            'educação', 'educacao'       => '📚',
            'lazer', 'entretenimento'    => '🎬',
            'casa'                       => '🏠',
            'compras'                    => '🛒',
            'salário', 'salario'         => '💰',
            'pets'                       => '🐶',
            default                      => '📦',
        };
    }
}
