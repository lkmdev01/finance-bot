<?php

namespace App\Http\Controllers;

use App\Models\AbacatePaySubscription;
use App\Services\AbacatePayService;
use App\Services\BillingPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BillingPlanController extends Controller
{
    public function __construct(
        private readonly BillingPlanService $billingPlanService,
        private readonly AbacatePayService $abacatePayService,
    ) {}

    public function index(Request $request)
    {
        return view('pages.billing.plans', [
            'plans' => $this->billingPlanService->all(),
            'currentPlan' => $this->billingPlanService->userPlan($request->user()),
        ]);
    }

    public function subscribe(Request $request, string $planCode): RedirectResponse
    {
        $user = $request->user();
        $plan = $this->billingPlanService->findOrFail($planCode);

        if (($plan['price_cents'] ?? 0) === 0) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'O plano Starter já está disponível sem cobrança.');
        }

        if ($user->hasActivePaidPlan() && $user->billing_plan_code === $planCode) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'Você já possui este plano ativo.');
        }

        try {
            $externalId = 'plan_'.$planCode.'_'.$user->id.'_'.Str::uuid();

            $response = $this->abacatePayService->createSubscriptionCheckout([
                'frequency' => 'ONE_TIME',
                'methods' => ['PIX', 'CARD'],
                'products' => [
                    [
                        'externalId' => $plan['code'],
                        'name' => $plan['name'],
                        'description' => $plan['description'],
                        'quantity' => 1,
                        'price' => $plan['price_cents'],
                    ],
                ],
                'externalId' => $externalId,
                'returnUrl' => route('billing.plans'),
                'completionUrl' => route('billing.plans', ['checkout' => 'success']),
                'metadata' => [
                    'app_user_id' => $user->id,
                    'plan_code' => $planCode,
                    'plan_name' => $plan['name'],
                    'source' => 'app_billing',
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('billing.plans')
                ->with('status', 'Não foi possível iniciar a assinatura agora. Verifique a configuração da AbacatePay.');
        }

        if (! ($response['success'] ?? false) || blank($response['data']['url'] ?? null)) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'Não foi possível iniciar a assinatura agora. Tente novamente.');
        }

        $data = $response['data'];

        AbacatePaySubscription::query()->updateOrCreate(
            ['external_id' => $externalId],
            [
                'user_id' => $user->id,
                'plan_code' => $planCode,
                'gateway_checkout_id' => $data['id'] ?? null,
                'checkout_url' => $data['url'] ?? null,
                'amount' => $data['amount'] ?? $plan['price_cents'],
                'status' => $data['status'] ?? 'PENDING',
                'frequency' => $plan['frequency'],
                'payload' => $data,
            ]
        );

        return redirect()->away($data['url']);
    }
}
