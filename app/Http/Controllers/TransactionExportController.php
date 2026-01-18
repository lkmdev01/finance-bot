<?php

namespace App\Http\Controllers;

use App\Exports\TransactionsExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class TransactionExportController extends Controller
{
    public function exportCsv(Request $request)
    {
        $user = Auth::user();
        $startDate = $request->get('start_date') ? \Carbon\Carbon::parse($request->get('start_date')) : null;
        $endDate = $request->get('end_date') ? \Carbon\Carbon::parse($request->get('end_date')) : null;
        
        $filename = 'transacoes_' . now()->format('Y-m-d') . '.csv';
        
        return Excel::download(
            new TransactionsExport($user, $startDate, $endDate),
            $filename
        );
    }
    
    public function exportExcel(Request $request)
    {
        $user = Auth::user();
        $startDate = $request->get('start_date') ? \Carbon\Carbon::parse($request->get('start_date')) : null;
        $endDate = $request->get('end_date') ? \Carbon\Carbon::parse($request->get('end_date')) : null;
        
        $filename = 'transacoes_' . now()->format('Y-m-d') . '.xlsx';
        
        return Excel::download(
            new TransactionsExport($user, $startDate, $endDate),
            $filename
        );
    }
    
    public function exportPdf(Request $request)
    {
        $user = Auth::user();
        $startDate = $request->get('start_date') ? \Carbon\Carbon::parse($request->get('start_date')) : null;
        $endDate = $request->get('end_date') ? \Carbon\Carbon::parse($request->get('end_date')) : null;
        
        $query = $user->transactions()->with('category');
        
        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }
        
        $transactions = $query->orderBy('date', 'desc')->get();
        
        $pdf = Pdf::loadView('transactions.export-pdf', [
            'transactions' => $transactions,
            'user' => $user,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
        
        $filename = 'transacoes_' . now()->format('Y-m-d') . '.pdf';
        
        return $pdf->download($filename);
    }
    
    public function exportJson(Request $request)
    {
        $user = Auth::user();
        $startDate = $request->get('start_date') ? \Carbon\Carbon::parse($request->get('start_date')) : null;
        $endDate = $request->get('end_date') ? \Carbon\Carbon::parse($request->get('end_date')) : null;
        
        $query = $user->transactions()->with('category');
        
        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }
        
        $transactions = $query->orderBy('date', 'desc')->get();
        
        $data = $transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'date' => $transaction->date->format('Y-m-d'),
                'description' => $transaction->description,
                'category' => $transaction->category?->name,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'created_at' => $transaction->created_at->toIso8601String(),
            ];
        });
        
        return response()->json($data, 200, [], JSON_PRETTY_PRINT);
    }
}
