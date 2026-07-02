<?php

namespace App\Http\Controllers;

use App\Models\AbacatePaySubscription;
use App\Services\AbacatePayService;
use App\Services\BillingPlanService;
use App\Support\BrazilTaxId;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BillingPlanController extends Controller
{
    public function __construct(
        private readonly BillingPlanService $billingPlanService,
        private readonly AbacatePayService $abacatePayService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $currentPlan = $this->billingPlanService->userPlan($user);

        $cancelableSubscription = AbacatePaySubscription::query()
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

        return view('pages.billing.plans', [
            'plans' => $this->billingPlanService->all(),
            'currentPlan' => $currentPlan,
            'cancelableSubscription' => $cancelableSubscription,
            'checkoutReturned' => $request->query('checkout') === 'success',
        ]);
    }

    public function showCheckoutData(Request $request, string $planCode)
    {
        $plan = $this->billingPlanService->findOrFail($planCode);

        if (! $this->isSellablePlan($plan)) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'Esta oferta não está mais disponível. Use a campanha Brasil na Copa do Pro Mensal.');
        }

        return view('pages.billing.checkout-data', [
            'plan' => $plan,
            'formattedPhoneNumber' => $this->formatBillingPhone($request->user()->phone_number),
        ]);
    }

    public function storeCheckoutData(Request $request, string $planCode): Response
    {
        $plan = $this->billingPlanService->findOrFail($planCode);

        if (! $this->isSellablePlan($plan)) {
            return redirect()
                ->route('billing.plans')
                ->with('status', 'Esta oferta não está mais disponível. Use a campanha Brasil na Copa do Pro Mensal.');
        }

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

    public function subscribe(Request $request, string $planCode): Response
    {
        $plan = $this->billingPlanService->findOrFail($planCode);
        $user = $request->user();

        if (! $this->isSellablePlan($plan)) {
            return $this->respondBillingStatus(
                $request,
                'Esta oferta não está mais disponível. Use a campanha Brasil na Copa do Pro Mensal.'
            );
        }

        if (($plan['price_cents'] ?? 0) === 0) {
            return $this->respondBillingStatus(
                $request,
                'O plano Inicial já está disponível sem cobrança.'
            );
        }

        if ($user->hasActivePaidPlan() && $user->billing_plan_code === $planCode) {
            return $this->respondBillingStatus(
                $request,
                'Você já possui este plano ativo.'
            );
        }

        // "Checkout invisivel": a tela de planos pode enviar tax_id inline (AJAX) sem precisar ir para a tela checkout-data.
        if ($request->filled('tax_id')) {
            $taxId = BrazilTaxId::normalize((string) $request->input('tax_id'));

            if (! BrazilTaxId::isValid($taxId)) {
                return $this->respondBillingValidationError($request, 'tax_id', 'Informe um CPF ou CNPJ valido.');
            }

            $user->forceFill(['tax_id' => $taxId])->save();
        }

        $missingBillingRequirements = $this->billingPlanService->missingBillingRequirements($user);

        if ($missingBillingRequirements !== []) {
            if ($request->expectsJson()) {
                $requiresTaxId = blank($user->tax_id) || ! BrazilTaxId::isValid($user->tax_id);

                return response()->json([
                    'ok' => false,
                    'error' => 'missing_billing_requirements',
                    'missing' => $missingBillingRequirements,
                    'requires_tax_id' => $requiresTaxId,
                    'message' => 'Confirme seus dados antes de seguir para o checkout.',
                ], 422);
            }

            return redirect()
                ->route('billing.checkout-data.show', $planCode)
                ->with('status', 'Confirme seus dados antes de seguir para o checkout.');
        }

        return $this->startCheckoutForPlan($request, $planCode);
    }

    protected function startCheckoutForPlan(Request $request, string $planCode): Response
    {
        $user = $request->user();
        $plan = $this->billingPlanService->findOrFail($planCode);

        $productId = $plan['product_id'] ?? null;
        if (blank($productId)) {
            return redirect()
                ->route('billing.checkout-data.show', $planCode)
                ->with('status', 'Este plano ainda não está configurado para pagamento. Entre em contato com o suporte.');
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
                        ->with('status', $customerResponse['error'] ?? 'Não foi possível iniciar o pagamento do plano agora. Tente novamente.');
                }

                $customerId = (string) $customerResponse['data']['id'];
                $user->forceFill(['abacatepay_customer_id' => $customerId])->save();
            }

            $flow = (string) ($plan['checkout_flow'] ?? 'checkout');

            if ($flow === 'subscription') {
                $methods = config('billing.subscription_methods', ['CARD']);
                if (! is_array($methods) || $methods === []) {
                    $methods = ['CARD'];
                }

                // Assinatura recorrente (renova automaticamente) via CARD.
                // O produto precisa ter `cycle` configurado na loja (ex: MONTHLY).
                $response = $this->abacatePayService->createSubscriptionCheckout([
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
            } else {
                $methods = config('billing.checkout_methods', ['PIX', 'CARD']);
                if (! is_array($methods) || $methods === []) {
                    $methods = ['PIX', 'CARD'];
                }

                // Pagamento avulso (sem renovação automática). O acesso é liberado pelo período do plano.
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
            }
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('billing.checkout-data.show', $planCode)
                ->with('status', 'Não foi possível iniciar o pagamento do plano agora. Verifique a configuração da AbacatePay.');
        }

        if (! ($response['success'] ?? false) || blank($response['data']['url'] ?? null)) {
            $message = $response['error'] ?? 'Não foi possível iniciar o pagamento do plano agora. Tente novamente.';

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'checkout_failed',
                    'message' => $message,
                ], 422);
            }

            return redirect()
                ->route('billing.checkout-data.show', $planCode)
                ->with('status', $message);
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

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'redirect_url' => $data['url'],
            ]);
        }

        return redirect()->away($data['url']);
    }

    protected function respondBillingStatus(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'error' => 'billing_status',
                'message' => $message,
            ], 422);
        }

        return redirect()
            ->route('billing.plans')
            ->with('status', $message);
    }

    protected function respondBillingValidationError(Request $request, string $field, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_error',
                'field' => $field,
                'message' => $message,
            ], 422);
        }

        return redirect()
            ->back()
            ->withErrors([$field => $message])
            ->withInput();
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

    protected function isSellablePlan(array $plan): bool
    {
        return ($plan['sellable'] ?? true) !== false;
    }
}
