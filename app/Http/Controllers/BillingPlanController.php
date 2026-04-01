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

        if (blank($plan['product_id'] ?? null)) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'O produto deste plano ainda não foi configurado na AbacatePay.');
        }

        if ($user->hasActivePaidPlan() && $user->billing_plan_code === $planCode) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'Você já possui este plano ativo.');
        }

        try {
            $customerId = $user->abacatepay_customer_id ?: $this->createOrReuseCustomer($user);

            if (! $user->abacatepay_customer_id && $customerId) {
                $user->forceFill([
                    'abacatepay_customer_id' => $customerId,
                ])->save();
            }

            $externalId = 'plan_'.$planCode.'_'.$user->id.'_'.Str::uuid();

            $response = $this->abacatePayService->createSubscriptionCheckout([
                'items' => [
                    [
                        'id' => $plan['product_id'],
                        'quantity' => 1,
                    ],
                ],
                'customerId' => $customerId,
                'methods' => ['CARD'],
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
                'gateway_customer_id' => $customerId,
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

    protected function createOrReuseCustomer($user): string
    {
        $response = $this->abacatePayService->createCustomer([
            'email' => $user->email,
            'name' => $user->name,
            'cellphone' => $user->phone_number,
            'metadata' => [
                'app_user_id' => $user->id,
                'source' => 'inovaforce-finance',
            ],
        ]);

        if (! ($response['success'] ?? false) || blank($response['data']['id'] ?? null)) {
            throw new \RuntimeException('Não foi possível criar o cliente na AbacatePay.');
        }

        return (string) $response['data']['id'];
    }
}