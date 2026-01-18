<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();

        if ($users->isEmpty()) {
            $this->command->warn('Nenhum usuário encontrado. Execute o DatabaseSeeder primeiro.');
            return;
        }

        $expenseCategories = [
            ['name' => 'Alimentação', 'color' => '#FF6B6B', 'icon' => '🍔'],
            ['name' => 'Transporte', 'color' => '#4ECDC4', 'icon' => '🚗'],
            ['name' => 'Moradia', 'color' => '#45B7D1', 'icon' => '🏠'],
            ['name' => 'Saúde', 'color' => '#96CEB4', 'icon' => '💊'],
            ['name' => 'Educação', 'color' => '#FFEAA7', 'icon' => '📚'],
            ['name' => 'Lazer', 'color' => '#DDA0DD', 'icon' => '🎬'],
            ['name' => 'Compras', 'color' => '#F39C12', 'icon' => '🛒'],
            ['name' => 'Assinaturas', 'color' => '#E74C3C', 'icon' => '📱'],
            ['name' => 'Outros', 'color' => '#95A5A6', 'icon' => '📦'],
        ];

        $incomeCategories = [
            ['name' => 'Salário', 'color' => '#2ECC71', 'icon' => '💰'],
            ['name' => 'Freelance', 'color' => '#3498DB', 'icon' => '💼'],
            ['name' => 'Investimentos', 'color' => '#9B59B6', 'icon' => '📈'],
            ['name' => 'Vendas', 'color' => '#E67E22', 'icon' => '🛍️'],
            ['name' => 'Outros', 'color' => '#95A5A6', 'icon' => '💵'],
        ];

        foreach ($users as $user) {
            foreach ($expenseCategories as $category) {
                Category::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'name' => $category['name'],
                        'type' => 'expense',
                    ],
                    [
                        'color' => $category['color'],
                        'icon' => $category['icon'],
                    ]
                );
            }

            foreach ($incomeCategories as $category) {
                Category::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'name' => $category['name'],
                        'type' => 'income',
                    ],
                    [
                        'color' => $category['color'],
                        'icon' => $category['icon'],
                    ]
                );
            }
        }
    }
}
