<?php

namespace Database\Factories;

use App\Models\SavingsGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SavingsGoalDeposit>
 */
class SavingsGoalDepositFactory extends Factory
{
    public function definition(): array
    {
        return [
            'savings_goal_id' => SavingsGoal::factory(),
            'amount' => fake()->randomFloat(2, 10, 500),
            'description' => fake()->optional()->sentence(),
            'deposit_date' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
