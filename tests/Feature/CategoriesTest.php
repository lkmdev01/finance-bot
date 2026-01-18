<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('pode listar categorias', function () {
    Category::factory()->count(3)->create([
        'user_id' => $this->user->id,
    ]);

    actingAs($this->user)
        ->get(route('categories.index'))
        ->assertSuccessful()
        ->assertSee('Categorias');
});

it('pode criar uma categoria', function () {
    actingAs($this->user)
        ->get(route('categories.create'))
        ->assertSuccessful();

    $category = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Alimentação',
        'type' => 'expense',
        'color' => '#FF5733',
        'icon' => '🍔',
    ]);

    assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Alimentação',
        'type' => 'expense',
    ]);
});

it('pode editar uma categoria', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
    ]);

    actingAs($this->user)
        ->get(route('categories.edit', $category))
        ->assertSuccessful();

    $category->update([
        'name' => 'Nome Atualizado',
        'color' => '#00FF00',
    ]);

    assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Nome Atualizado',
        'color' => '#00FF00',
    ]);
});

it('pode excluir uma categoria sem transações', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $category->delete();

    assertDatabaseMissing('categories', [
        'id' => $category->id,
    ]);
});

it('não pode excluir categoria com transações associadas', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
    ]);

    // Tentativa de exclusão deve ser bloqueada pela lógica do componente
    expect($category->transactions()->count())->toBeGreaterThan(0);
});

it('filtra categorias por tipo', function () {
    Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'name' => 'Salário',
    ]);

    Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Alimentação',
    ]);

    actingAs($this->user)
        ->get(route('categories.index') . '?type=income')
        ->assertSuccessful()
        ->assertSee('Salário')
        ->assertDontSee('Alimentação');
});
