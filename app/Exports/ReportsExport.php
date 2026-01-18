<?php

namespace App\Exports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportsExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        protected Collection $transactions,
        protected float $totalIncome,
        protected float $totalExpenses,
        protected float $balance
    ) {
    }

    public function collection(): Collection
    {
        return $this->transactions;
    }

    public function headings(): array
    {
        return [
            'Data',
            'Descrição',
            'Categoria',
            'Tipo',
            'Valor',
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->date->format('d/m/Y'),
            $transaction->description ?? '-',
            $transaction->category?->name ?? '-',
            $transaction->type === 'income' ? 'Receita' : 'Despesa',
            number_format($transaction->amount, 2, ',', '.'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
