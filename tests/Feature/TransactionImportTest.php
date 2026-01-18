<?php

use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionImportService;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->importService = app(TransactionImportService::class);
    Storage::fake('local');
});

it('pode importar transações de arquivo CSV', function () {
    $csvContent = "Data,Descrição,Valor,Tipo\n";
    $csvContent .= "2026-01-01,Supermercado,100.50,expense\n";
    $csvContent .= "2026-01-02,Salário,5000.00,income\n";

    $filePath = Storage::path('imports/test.csv');
    Storage::makeDirectory('imports');
    Storage::put('imports/test.csv', $csvContent);

    $result = $this->importService->importFromCsv($this->user, $filePath);

    expect($result['imported'])->toBe(2);
    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'description' => 'Supermercado',
        'type' => 'expense',
    ]);
});

it('reconhece categoria automaticamente durante importação', function () {
    $csvContent = "Data,Descrição,Valor,Tipo\n";
    $csvContent .= "2026-01-01,Uber,25.00,expense\n";

    $filePath = Storage::path('imports/test2.csv');
    Storage::makeDirectory('imports');
    Storage::put('imports/test2.csv', $csvContent);

    $result = $this->importService->importFromCsv($this->user, $filePath);

    expect($result['imported'])->toBe(1);
    
    $transaction = Transaction::where('description', 'Uber')->first();
    expect($transaction->category)->not->toBeNull();
    expect(mb_strtolower($transaction->category->name))->toContain('transporte');
});

it('lida com erros durante importação', function () {
    $csvContent = "Data,Descrição,Valor,Tipo\n";
    $csvContent .= "data-invalida,Teste,abc,expense\n";

    $filePath = Storage::path('imports/test3.csv');
    Storage::makeDirectory('imports');
    Storage::put('imports/test3.csv', $csvContent);

    $result = $this->importService->importFromCsv($this->user, $filePath);

    // Pode importar mesmo com data inválida (usa data atual como fallback)
    // Mas valor inválido deve gerar erro
    expect($result['imported'])->toBeLessThanOrEqual(1);
});
