<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BudgetExceededNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
    ]);
});

it('envia notificação quando orçamento é excedido', function () {
    Notification::fake();

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 1000.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    // Criar transações que excedem o orçamento
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'type' => 'expense',
        'amount' => 1200.00,
        'date' => now(),
    ]);

    artisan('budgets:check-exceeded');

    Notification::assertSentTo(
        $this->user,
        BudgetExceededNotification::class,
        function ($notification) use ($budget) {
            return $notification->budget->id === $budget->id;
        }
    );
});

it('não envia notificação duplicada no mesmo dia', function () {
    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 1000.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'type' => 'expense',
        'amount' => 1200.00,
        'date' => now(),
    ]);

    // Primeira execução
    Notification::fake();
    artisan('budgets:check-exceeded');
    Notification::assertSentTo($this->user, BudgetExceededNotification::class);

    // Segunda execução no mesmo dia - verifica que não cria nova notificação
    $notificationCountBefore = $this->user->notifications()
        ->where('type', BudgetExceededNotification::class)
        ->where('data->budget_id', $budget->id)
        ->whereDate('created_at', today())
        ->count();

    artisan('budgets:check-exceeded');

    $notificationCountAfter = $this->user->notifications()
        ->where('type', BudgetExceededNotification::class)
        ->where('data->budget_id', $budget->id)
        ->whereDate('created_at', today())
        ->count();

    expect($notificationCountAfter)->toBe($notificationCountBefore);
});

it('não envia notificação quando orçamento não é excedido', function () {
    Notification::fake();

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 1000.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'type' => 'expense',
        'amount' => 500.00,
        'date' => now(),
    ]);

    artisan('budgets:check-exceeded');

    Notification::assertNothingSent();
});
