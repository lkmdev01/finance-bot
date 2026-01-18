<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Cache;

class AIContextBuilder
{
    public function __construct(
        private readonly FinancialDataCalculator $financialCalculator
    ) {}

    /**
     * Constrói o contexto completo para a IA
     */
    public function build(User $user, ?WhatsAppContact $contact): array
    {
        $recentTransactions = $user->transactions()
            ->latest('date')
            ->limit(15)
            ->with(['category', 'whatsappContact'])
            ->get();

        $categories = $user->categories()
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'type' => $cat->type,
            ])
            ->toArray();

        // Calcula dados financeiros para contexto (com cache de 5 minutos)
        $financialData = Cache::remember(
            "user.{$user->id}.financial_data",
            300, // 5 minutos
            fn() => $this->financialCalculator->calculate($user)
        );
        
        // Adiciona contexto de conversa recente (últimas 5 interações)
        $recentContext = $contact?->context ?? [];
        $lastInteractions = array_slice($recentContext, -5);

        return [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'recent_transactions' => $recentTransactions->map(fn ($t) => [
                'id' => $t->id, // Adiciona ID para edição/exclusão
                'type' => $t->type,
                'amount' => $t->amount,
                'description' => $t->description,
                'category' => $t->category?->name,
                'category_id' => $t->category_id,
                'date' => $t->date->format('Y-m-d'),
            ])->toArray(),
            'categories' => $categories,
            'financial_data' => $financialData,
            'contact_context' => $lastInteractions,
        ];
    }
}
