<?php

namespace App\Http\Controllers;

use App\Models\AbacatePaySubscription;
use App\Services\AbacatePayService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillingSubscriptionController extends Controller
{
    public function __construct(
        private readonly AbacatePayService $abacatePayService,
    ) {}

    public function cancel(Request $request): Response
    {
        $user = $request->user();

        $subscription = AbacatePaySubscription::query()
            ->where('user_id', $user->id)
            ->whereNotNull('gateway_subscription_id')
            ->whereNull('cancelled_at')
            ->where(function ($query) {
                $query
                    ->whereNull('status')
                    ->orWhereNotIn('status', ['CANCELLED', 'cancelled', 'CANCELED', 'canceled']);
            })
            ->latest('id')
            ->first();

        if (! $subscription) {
            return $this->respond($request, false, 'Nao encontrei uma assinatura ativa para cancelar.', 404);
        }

        try {
            $response = $this->abacatePayService->cancelSubscription([
                'id' => $subscription->gateway_subscription_id,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->respond($request, false, 'Nao foi possivel cancelar sua assinatura agora. Tente novamente em alguns instantes.', 422);
        }

        if (! ($response['success'] ?? false)) {
            return $this->respond(
                $request,
                false,
                $response['error'] ?? 'Nao foi possivel cancelar sua assinatura agora.',
                422
            );
        }

        $subscription->forceFill([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
            'payload' => $response,
        ])->save();

        // AbacatePay cancela imediatamente (cancelPolicy NOW). Alinhamos o acesso do app com isso.
        $user->forceFill([
            'billing_plan_status' => 'cancelled',
            'billing_access_ends_at' => now(),
        ])->save();

        return $this->respond($request, true, 'Assinatura cancelada. Seu acesso premium foi encerrado agora.');
    }

    private function respond(Request $request, bool $ok, string $message, int $status = 200): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
            ], $status);
        }

        return redirect()
            ->route('billing.plans')
            ->with('status', $message);
    }
}

