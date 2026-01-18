<?php

namespace Database\Factories;

use App\Models\SavingsGoal;
use App\Models\SavingsGoalAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavingsGoalAlertFactory extends Factory
{
    protected $model = SavingsGoalAlert::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'savings_goal_id' => SavingsGoal::factory(),
            'type' => $this->faker->randomElement(['milestone', 'deadline', 'low_progress']),
            'threshold_percentage' => $this->faker->randomElement([null, $this->faker->numberBetween(10, 90)]),
            'days_before_deadline' => $this->faker->randomElement([null, $this->faker->numberBetween(1, 30)]),
            'is_active' => true,
            'last_triggered_at' => null,
        ];
    }

    public function milestone(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'milestone',
            'threshold_percentage' => $this->faker->numberBetween(10, 90),
            'days_before_deadline' => null,
        ]);
    }

    public function deadline(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'deadline',
            'threshold_percentage' => null,
            'days_before_deadline' => $this->faker->numberBetween(1, 30),
        ]);
    }

    public function lowProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'low_progress',
            'threshold_percentage' => null,
            'days_before_deadline' => null,
        ]);
    }
}
