<?php

namespace App\Http\Controllers;

use App\Models\AbacatePaySubscription;
use App\Services\AbacatePayService;
use App\Services\BillingPlanService;
use App\Support\BrazilTaxId;
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

    public function showCheckoutData(Request $request, string $planCode)
    {
        $plan = $this->billingPlanService->findOrFail($planCode);

        return view('pages.billing.checkout-data', [
            'plan' => $plan,
            'formattedPhoneNumber' => $this->formatBillingPhone($request->user()->phone_number),
        ]);
    }

    public function storeCheckoutData(Request $request, string $planCode): RedirectResponse
    {
        $this->billingPlanService->findOrFail($planCode);

        $validated = $request->validate([
            'tax_id' => [
                'required',
                'string',
                'max:20',
                function (string $attribute, string $value, \Closure $fail) {
                    if (! BrazilTaxId::isValid($value)) {
                        $fail('Informe um CPF ou CNPJ valido.');
                    }
                },
            ],
        ]);

        $user = $request->user();
        $user->forceFill([
            'tax_id' => BrazilTaxId::normalize($validated['tax_id']),
        ])->save();

        return $this->startCheckoutForPlan($request, $planCode);
    }

    public function subscribe(Request $request, string $planCode): RedirectResponse
    {
        $plan = $this->billingPlanService->findOrFail($planCode);
        $user = $request->user();

        if (($plan['price_cents'] ?? 0) === 0) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'O plano Starter ja esta disponivel sem cobranca.');
        }

        if ($user->hasActivePaidPlan() && $user->billing_plan_code === $planCode) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'Voce ja possui este plano ativo.');
        }

        $missingBillingRequirements = $this->billingPlanService->missingBillingRequirements($user);

        if ($missingBillingRequirements !== []) {
            return redirect()
                ->route('billing.checkout-data.show', $planCode)
                ->with('status', 'Confirme seus dados antes de seguir para o checkout.');
        }

        return $this->startCheckoutForPlan($request, $planCode);
    }

    protected function startCheckoutForPlan(Request $request, string $planCode): RedirectResponse
    {
        $user = $request->user();
        $plan = $this->billingPlanService->findOrFail($planCode);

        $productId = $plan['product_id'] ?? null;
        if (blank($productId)) {
            return redirect()
                ->route('billing.checkout-data.show', $planCode)
                ->with('status', 'Este plano ainda nao esta configurado para pagamento. Entre em contato com o suporte.');
        }

        try {
            $externalId = 'plan_'.$planCode.'_'.$user->id.'_'.Str::uuid();

            $customerId = $user->abacatepay_customer_id;

            if (blank($customerId)) {
                $customerResponse = $this->abacatePayService->createCustomer([
                    'name' => $user->name,
                    'email' => $user->email,
                    'cellphone' => $this->formatBillingPhone($user->phone_number),
                    'taxId' => BrazilTaxId::format($user->tax_id),
                    'metadata' => [
                        'app_user_id' => $user->id,
                        'source' => 'app_billing',
                    ],
                ]);

                if (! ($customerResponse['success'] ?? false) || blank($customerResponse['data']['id'] ?? null)) {
                    return redirect()
                        ->route('billing.checkout-data.show', $planCode)
                        ->with('status', $customerResponse['error'] ?? 'Nao foi possivel iniciar o pagamento do plano agora. Tente novamente.');
                }

                $customerId = (string) $customerResponse['data']['id'];
                $user->forceFill(['abacatepay_customer_id' => $customerId])->save();
            }

            $methods = config('billing.checkout_methods', ['PIX', 'CARD']);
            if (! is_array($methods) || $methods === []) {
                $methods = ['PIX', 'CARD'];
            }

            // MVP: cobranca avulsa (sem renovacao automatica). O acesso e liberado pelo periodo do plano.
            $response = $this->abacatePayService->createCheckout([
                'items' => [
                    [
                        'id' => $productId,
                        'quantity' => 1,
                    ],
                ],
                'customerId' => $customerId,
                'methods' => array_values($methods),
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
                ->route('billing.checkout-data.show', $planCode)
                ->with('status', 'Nao foi possivel iniciar o pagamento do plano agora. Verifique a configuracao da AbacatePay.');
        }

        if (! ($response['success'] ?? false) || blank($response['data']['url'] ?? null)) {
            return redirect()
                ->route('billing.checkout-data.show', $planCode)
                ->with('status', $response['error'] ?? 'Nao foi possivel iniciar o pagamento do plano agora. Tente novamente.');
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
                'gateway_customer_id' => $user->abacatepay_customer_id,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_tax_id' => BrazilTaxId::format($user->tax_id),
                'payload' => $data,
            ]
        );

        return redirect()->away($data['url']);
    }

    protected function formatBillingPhone(?string $phoneNumber): ?string
    {
        if (blank($phoneNumber)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phoneNumber);

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        return match (strlen($digits)) {
            10 => preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $digits),
            11 => preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $digits),
            default => $phoneNumber,
        };
    }
}
