<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BillingPlanService;
use App\Services\CategoryRecognitionService;
use App\Services\WhatsApp\FinancialSourceResolver;
use Illuminate\Support\Facades\Validator;

class CreateSubscriptionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_subscription';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $subscriptionData = $this->normalizeSubscriptionData($result['subscription_data'] ?? []);
        $billingPlanService = app(BillingPlanService::class);

        if (! $billingPlanService->userCanCreateRecords($user)) {
            $plansUrl = rtrim((string) config('app.url'), '/').'/billing/plans';
            $reply = $billingPlanService->writeAccessMessage($user)
                ."\n\nAssine um plano para voltar a registrar novas informacoes:\n"
                .$plansUrl;
            $this->sendResponse($job, $reply, $user);

            return true;
        }

        $validation = Validator::make($subscriptionData, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'start_date' => ['required', 'date'],
            'category_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'credit_card_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, $this->buildGuidanceReply($validation->errors()->all()));

            return true;
        }

        $subscription = $this->createSubscription($user, $subscriptionData);
        $reply = $this->buildCreatedReply($subscription);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => [
                'kind' => 'subscription_create',
                'id' => $subscription->id,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
            'entities' => array_filter([
                'topic' => 'subscriptions',
                'subscription_name' => $subscription->name,
                'subscription_id' => $subscription->id,
            ]),
        ]);

        $this->sendResponse($job, $reply, $user);

        return true;
    }

    private function normalizeSubscriptionData(array $data): array
    {
        $cycle = $data['billing_cycle'] ?? 'monthly';

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'billing_cycle' => in_array($cycle, ['monthly', 'yearly'], true) ? $cycle : 'monthly',
            'due_day' => isset($data['due_day']) ? (int) $data['due_day'] : now()->day,
            'start_date' => (string) ($data['start_date'] ?? now()->toDateString()),
            'auto_record' => (bool) ($data['auto_record'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'category_name' => trim((string) ($data['category_name'] ?? 'Assinaturas')),
            'bank_account_name' => trim((string) ($data['bank_account_name'] ?? '')) ?: null,
            'credit_card_name' => trim((string) ($data['credit_card_name'] ?? '')) ?: null,
        ];
    }

    private function createSubscription(User $user, array $data): Subscription
    {
        $categoryRecognition = app(CategoryRecognitionService::class);
        $category = null;

        if ($data['category_name'] !== '') {
            $category = $categoryRecognition->findExistingCategoryByName($user, $data['category_name'], 'expense');
        }

        if (! $category && $data['category_name'] !== '') {
            $category = $categoryRecognition->findOrCreateCategory($user, $data['category_name'], 'expense');
        }

        [$bankAccount, $creditCard] = app(FinancialSourceResolver::class)->resolve($user, $data);

        return Subscription::query()->create([
            'user_id' => $user->id,
            'category_id' => $category?->id,
            'bank_account_id' => $bankAccount?->id,
            'credit_card_id' => $creditCard?->id,
            'name' => $data['name'],
            'description' => 'Assinatura: '.$data['name'],
            'amount' => $data['amount'],
            'billing_cycle' => $data['billing_cycle'],
            'due_day' => $data['due_day'],
            'start_date' => $data['start_date'],
            'auto_record' => $data['auto_record'],
            'is_active' => $data['is_active'],
        ])->load('category');
    }

    private function buildCreatedReply(Subscription $subscription): string
    {
        $amount = number_format((float) $subscription->amount, 2, ',', '.');
        $cycle = $subscription->billing_cycle === 'yearly' ? 'anual' : 'mensal';

        return sprintf(
            'Assinatura %s criada com valor de R$ %s no ciclo %s e vencimento no dia %d.',
            $subscription->name,
            $amount,
            $cycle,
            (int) $subscription->due_day
        );
    }

    private function buildGuidanceReply(array $errors = []): string
    {
        $details = empty($errors) ? '' : "\n\nDetalhes: ".implode(' | ', $errors);

        return "Nao consegui criar essa assinatura com a mensagem atual.\n\n"
            ."Tente assim:\n"
            ."* criar assinatura Netflix mensal dia 10 19 reais\n"
            ."* definir assinatura Spotify mensal dia 5 21,90\n"
            ."* criar assinatura Academia anual dia 15 800"
            .$details;
    }
}
