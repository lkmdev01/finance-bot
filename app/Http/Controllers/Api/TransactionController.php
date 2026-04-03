<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\BillingPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class TransactionController extends Controller
{
    public function __construct(
        private readonly BillingPlanService $billingPlanService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Auth::user()->transactions()->with('category');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        $transactions = $query->orderBy('date', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($transactions);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->billingPlanService->userCanCreateRecords($request->user())) {
            return response()->json([
                'message' => $this->billingPlanService->writeAccessMessage($request->user()),
            ], 403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $transaction = Auth::user()->transactions()->create($validated);

        return response()->json($transaction, 201);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        Gate::authorize('view', $transaction);

        return response()->json($transaction->load('category'));
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        Gate::authorize('update', $transaction);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:income,expense'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'date' => ['sometimes', 'date'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $transaction->update($validated);

        return response()->json($transaction->load('category'));
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        Gate::authorize('delete', $transaction);

        $transaction->delete();

        return response()->json(['message' => 'Transaction deleted'], 200);
    }
}
