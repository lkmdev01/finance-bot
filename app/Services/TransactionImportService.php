<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class TransactionImportService
{
    public function __construct(
        protected CategoryRecognitionService $categoryRecognition
    ) {
    }

    public function importFromCsv(User $user, string $filePath, array $mapping = [], array $defaults = []): array
    {
        $handle = fopen($filePath, 'r');
        $imported = 0;
        $errors = [];

        // Pular cabeçalho se existir
        $header = fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            try {
                $transaction = $this->parseCsvRow($user, $row, $mapping, $defaults);
                $transaction->save();
                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $row,
                    'error' => $e->getMessage(),
                ];
            }
        }

        fclose($handle);

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }

    protected function parseCsvRow(User $user, array $row, array $mapping, array $defaults = []): Transaction
    {
        $defaultMapping = [
            'date' => 0,
            'description' => 1,
            'amount' => 2,
            'type' => 3,
        ];

        $mapping = array_merge($defaultMapping, $mapping);

        $date = $this->parseDate($row[$mapping['date']] ?? '');
        $description = trim($row[$mapping['description']] ?? '');
        $amount = $this->parseAmount($row[$mapping['amount']] ?? '0');
        $type = $this->parseType($row[$mapping['type']] ?? '', $amount);

        // Reconhecer categoria automaticamente
        $category = $this->categoryRecognition->recognizeCategory($user, $description, $amount);

        return new Transaction(array_merge([
            'user_id' => $user->id,
            'category_id' => $category?->id,
            'type' => $type,
            'amount' => abs($amount),
            'description' => $description,
            'date' => $date,
        ], $defaults));
    }

    protected function parseDate(string $date): string
    {
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return now()->format('Y-m-d');
        }
    }

    protected function parseAmount(string $amount): float
    {
        // Remover formatação de moeda
        $amount = trim($amount);
        $amount = str_replace(['R$', '$', ' '], '', $amount);
        
        // Se tem vírgula, assume formato brasileiro (1.234,56)
        if (str_contains($amount, ',')) {
            $amount = str_replace('.', '', $amount); // Remove pontos de milhar
            $amount = str_replace(',', '.', $amount); // Converte vírgula para ponto
        }

        return (float) $amount;
    }

    protected function parseType(string $type, float $amount): string
    {
        $type = mb_strtolower(trim($type));

        if (in_array($type, ['receita', 'income', 'entrada', '+'])) {
            return 'income';
        }

        if (in_array($type, ['despesa', 'expense', 'saída', '-'])) {
            return 'expense';
        }

        // Se não especificado, assume pelo sinal do valor
        return $amount >= 0 ? 'income' : 'expense';
    }

    public function importFromOfx(User $user, string $filePath, array $defaults = []): array
    {
        // Implementação básica de OFX
        // OFX é um formato XML complexo, esta é uma versão simplificada
        $content = file_get_contents($filePath);
        $imported = 0;
        $errors = [];

        // Parse básico (implementação completa requer biblioteca OFX)
        if (preg_match_all('/<STMTTRN>.*?<DTPOSTED>(\d{8})/s', $content, $dates)) {
            preg_match_all('/<MEMO>(.*?)<\/MEMO>/s', $content, $descriptions);
            preg_match_all('/<TRNAMT>([-]?\d+\.?\d*)/s', $content, $amounts);

            for ($i = 0; $i < count($dates[1]); $i++) {
                try {
                    $date = Carbon::createFromFormat('Ymd', $dates[1][$i])->format('Y-m-d');
                    $description = $descriptions[1][$i] ?? 'Importação OFX';
                    $amount = (float) ($amounts[1][$i] ?? 0);
                    $type = $amount >= 0 ? 'income' : 'expense';

                    $category = $this->categoryRecognition->recognizeCategory($user, $description, abs($amount));

                    Transaction::create(array_merge([
                        'user_id' => $user->id,
                        'category_id' => $category?->id,
                        'type' => $type,
                        'amount' => abs($amount),
                        'description' => $description,
                        'date' => $date,
                    ], $defaults));

                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'row' => $i,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
