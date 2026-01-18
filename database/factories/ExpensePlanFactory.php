<?php

namespace Database\Factories;

use App\Models\ExpensePlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpensePlanFactory extends Factory
{
    protected $model = ExpensePlan::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('-1 month', '+1 month');
        $endDate = (clone $startDate)->modify('+1 month');

        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'planned_amount' => $this->faker->randomFloat(2, 100, 5000),
            'spent_amount' => 0,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'categories' => null,
            'is_active' => true,
        ];
    }
}
