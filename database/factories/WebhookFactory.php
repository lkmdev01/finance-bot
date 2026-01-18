<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'url' => $this->faker->url(),
            'secret' => $this->faker->optional()->password(),
            'events' => $this->faker->randomElements([
                'transaction.created',
                'transaction.updated',
                'transaction.deleted',
                'budget.exceeded',
                'savings_goal.milestone',
                'savings_goal.deadline',
                'savings_goal.low_progress',
                'expense_plan.exceeded',
            ], $this->faker->numberBetween(1, 3)),
            'is_active' => true,
            'success_count' => 0,
            'failure_count' => 0,
            'last_triggered_at' => null,
        ];
    }
}
