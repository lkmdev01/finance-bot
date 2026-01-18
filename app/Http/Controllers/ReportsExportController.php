<?php

namespace App\Http\Controllers;

use App\Exports\ReportsExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ReportsExportController extends Controller
{
    public function exportPdf(Request $request)
    {
        $user = Auth::user();
        $period = $request->get('period', 'monthly');
        $selectedMonth = $request->get('selectedMonth', now()->format('Y-m'));
        $year = $request->get('year', now()->year);

        if ($period === 'monthly') {
            [$year, $month] = explode('-', $selectedMonth);
            $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();
        } else {
            $startDate = \Carbon\Carbon::create($year, 1, 1)->startOfYear();
            $endDate = \Carbon\Carbon::create($year, 12, 31)->endOfYear();
        }

        $transactions = $user->transactions()
            ->whereBetween('date', [$startDate, $endDate])
            ->with('category')
            ->orderBy('date')
            ->get();

        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpenses = $transactions->where('type', 'expense')->sum('amount');
        $balance = $totalIncome - $totalExpenses;

        $pdf = Pdf::loadView('reports.pdf', [
            'transactions' => $transactions,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'balance' => $balance,
            'period' => $period,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'user' => $user,
        ]);

        $filename = 'relatorio_' . ($period === 'monthly' ? $selectedMonth : $year) . '.pdf';

        return $pdf->download($filename);
    }

    public function exportExcel(Request $request)
    {
        $user = Auth::user();
        $period = $request->get('period', 'monthly');
        $selectedMonth = $request->get('selectedMonth', now()->format('Y-m'));
        $year = $request->get('year', now()->year);

        if ($period === 'monthly') {
            [$year, $month] = explode('-', $selectedMonth);
            $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();
        } else {
            $startDate = \Carbon\Carbon::create($year, 1, 1)->startOfYear();
            $endDate = \Carbon\Carbon::create($year, 12, 31)->endOfYear();
        }

        $transactions = $user->transactions()
            ->whereBetween('date', [$startDate, $endDate])
            ->with('category')
            ->orderBy('date')
            ->get();

        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpenses = $transactions->where('type', 'expense')->sum('amount');
        $balance = $totalIncome - $totalExpenses;

        $filename = 'relatorio_' . ($period === 'monthly' ? $selectedMonth : $year) . '.xlsx';

        return Excel::download(
            new ReportsExport($transactions, $totalIncome, $totalExpenses, $balance),
            $filename
        );
    }
}
