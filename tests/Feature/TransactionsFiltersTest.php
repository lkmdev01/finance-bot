<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Alimentação',
    ]);
    $this->category2 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Transporte',
    ]);
});

it('pode acessar página de transações com filtros', function () {
    Transaction::factory()->count(5)->create([
        'user_id' => $this->user->id,
    ]);

    actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertSuccessful()
        ->assertSee('Transações')
        ->assertSee('Filtros');
});

it('exibe filtros de busca e tipo na página de transações', function () {
    actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertSuccessful()
        ->assertSee('Buscar por descrição ou categoria')
        ->assertSee('Tipo')
        ->assertSee('Categoria')
        ->assertSee('Ordenar por');
});

it('exibe filtros de data e valor na página de transações', function () {
    actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertSuccessful()
        ->assertSee('Data Inicial')
        ->assertSee('Data Final')
        ->assertSee('Valor mínimo')
        ->assertSee('Valor máximo');
});

it('exibe botão para limpar filtros', function () {
    actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertSuccessful()
        ->assertSee('Limpar Filtros');
});

it('exibe colunas ordenáveis na tabela', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
    ]);

    actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertSuccessful()
        ->assertSee('Data')
        ->assertSee('Descrição')
        ->assertSee('Valor');
});
