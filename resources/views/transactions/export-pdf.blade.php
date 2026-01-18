<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Exportação de Transações</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
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
        .header {
            margin-bottom: 20px;
        }
        .summary {
            margin-top: 20px;
            padding: 10px;
            background-color: #f9f9f9;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Exportação de Transações</h1>
        <p><strong>Usuário:</strong> {{ $user->name }}</p>
        @if($startDate || $endDate)
            <p><strong>Período:</strong> 
                {{ $startDate ? $startDate->format('d/m/Y') : 'Início' }} 
                até 
                {{ $endDate ? $endDate->format('d/m/Y') : 'Fim' }}
            </p>
        @endif
        <p><strong>Data de Exportação:</strong> {{ now()->format('d/m/Y H:i:s') }}</p>
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
                    <td>R$ {{ number_format($transaction->amount, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <p><strong>Total de Transações:</strong> {{ $transactions->count() }}</p>
        <p><strong>Total de Receitas:</strong> R$ {{ number_format($transactions->where('type', 'income')->sum('amount'), 2, ',', '.') }}</p>
        <p><strong>Total de Despesas:</strong> R$ {{ number_format($transactions->where('type', 'expense')->sum('amount'), 2, ',', '.') }}</p>
        <p><strong>Saldo:</strong> R$ {{ number_format($transactions->where('type', 'income')->sum('amount') - $transactions->where('type', 'expense')->sum('amount'), 2, ',', '.') }}</p>
    </div>
</body>
</html>
