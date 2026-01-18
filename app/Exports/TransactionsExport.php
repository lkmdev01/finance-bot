<?php

namespace App\Exports;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionsExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        protected User $user,
        protected ?\Carbon\Carbon $startDate = null,
        protected ?\Carbon\Carbon $endDate = null
    ) {}

    public function collection(): Collection
    {
        $query = $this->user->transactions()->with('category');
        
        if ($this->startDate) {
            $query->where('date', '>=', $this->startDate);
        }
        
        if ($this->endDate) {
            $query->where('date', '<=', $this->endDate);
        }
        
        return $query->orderBy('date', 'desc')->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Data',
            'Descrição',
            'Categoria',
            'Tipo',
            'Valor',
            'Criado em',
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->id,
            $transaction->date->format('d/m/Y'),
            $transaction->description ?? '-',
            $transaction->category?->name ?? '-',
            $transaction->type === 'income' ? 'Receita' : 'Despesa',
            number_format($transaction->amount, 2, ',', '.'),
            $transaction->created_at->format('d/m/Y H:i:s'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
