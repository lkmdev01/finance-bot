<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RecurringTransaction>
 */
class RecurringTransactionFactory extends Factory
{
    public function definition(): array
    {
        $frequency = fake()->randomElement(['daily', 'weekly', 'monthly', 'yearly']);
        $dayOfMonth = $frequency === 'monthly' ? fake()->numberBetween(1, 28) : null;
        $dayOfWeek = $frequency === 'weekly' ? fake()->numberBetween(0, 6) : null;

        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'type' => fake()->randomElement(['income', 'expense']),
            'amount' => fake()->randomFloat(2, 10, 1000),
            'description' => fake()->sentence(),
            'frequency' => $frequency,
            'start_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'end_date' => fake()->optional()->dateTimeBetween('now', '+1 year'),
            'last_processed_at' => fake()->optional()->dateTimeBetween('-1 week', 'now'),
            'is_active' => true,
            'day_of_month' => $dayOfMonth,
            'day_of_week' => $dayOfWeek,
        ];
    }
}
