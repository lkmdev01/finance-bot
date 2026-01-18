<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Financeiro</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .summary {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        .summary-row {
            display: table-row;
        }
        .summary-cell {
            display: table-cell;
            padding: 10px;
            border: 1px solid #ddd;
            text-align: right;
        }
        .summary-label {
            font-weight: bold;
            text-align: left;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .income {
            color: #22c55e;
        }
        .expense {
            color: #ef4444;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Relatório Financeiro</h1>
        <p><strong>Usuário:</strong> {{ $user->name }}</p>
        <p><strong>Período:</strong> {{ $startDate->format('d/m/Y') }} até {{ $endDate->format('d/m/Y') }}</p>
    </div>

    <div class="summary">
        <div class="summary-row">
            <div class="summary-cell summary-label">Total de Receitas:</div>
            <div class="summary-cell income">R$ {{ number_format($totalIncome, 2, ',', '.') }}</div>
        </div>
        <div class="summary-row">
            <div class="summary-cell summary-label">Total de Despesas:</div>
            <div class="summary-cell expense">R$ {{ number_format($totalExpenses, 2, ',', '.') }}</div>
        </div>
        <div class="summary-row">
            <div class="summary-cell summary-label">Saldo:</div>
            <div class="summary-cell {{ $balance >= 0 ? 'income' : 'expense' }}">R$ {{ number_format($balance, 2, ',', '.') }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Descrição</th>
                <th>Categoria</th>
                <th>Tipo</th>
                <th>Valor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->date->format('d/m/Y') }}</td>
                    <td>{{ $transaction->description ?? '-' }}</td>
                    <td>{{ $transaction->category?->name ?? '-' }}</td>
                    <td>{{ $transaction->type === 'income' ? 'Receita' : 'Despesa' }}</td>
                    <td class="{{ $transaction->type === 'income' ? 'income' : 'expense' }}">
                        {{ $transaction->type === 'income' ? '+' : '-' }}R$ {{ number_format($transaction->amount, 2, ',', '.') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>

