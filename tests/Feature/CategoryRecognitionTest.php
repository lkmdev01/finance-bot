<?php

use App\Models\Category;
use App\Models\User;
use App\Services\CategoryRecognitionService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->categoryService = app(CategoryRecognitionService::class);
});

it('reconhece categoria de alimentação', function () {
    $category = $this->categoryService->recognizeCategory($this->user, 'Compra no supermercado', 100.00);

    expect($category)->not->toBeNull();
    expect(mb_strtolower($category->name))->toContain('alimentação');
    expect($category->type)->toBe('expense');
});

it('reconhece categoria de transporte', function () {
    $category = $this->categoryService->recognizeCategory($this->user, 'Viagem de Uber', 25.00);

    expect($category)->not->toBeNull();
    expect(mb_strtolower($category->name))->toContain('transporte');
});

it('reconhece categoria de salário', function () {
    $category = $this->categoryService->recognizeCategory($this->user, 'Pagamento de salário', 5000.00);

    expect($category)->not->toBeNull();
    expect($category->type)->toBe('income');
});

it('cria categoria se não existir', function () {
    $category = $this->categoryService->recognizeCategory($this->user, 'Supermercado Extra', 150.00);

    expect($category)->not->toBeNull();
    expect(Category::where('user_id', $this->user->id)->where('name', 'like', '%alimentação%')->exists())->toBeTrue();
});

it('retorna null se não conseguir reconhecer', function () {
    $category = $this->categoryService->recognizeCategory($this->user, 'Descrição aleatória xyz123', 50.00);

    expect($category)->toBeNull();
});
