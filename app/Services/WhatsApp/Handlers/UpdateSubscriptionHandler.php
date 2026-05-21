<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\FinancialSourceResolver;
use Illuminate\Support\Facades\Validator;

class UpdateSubscriptionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'update_subscription';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $this->normalizeData($result['subscription_data'] ?? []);

        $validation = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'credit_card_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, $this->buildGuidanceReply($validation->errors()->all()));

            return true;
        }

        $subscription = $user->subscriptions()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->first();

        if (! $subscription instanceof Subscription) {
            $this->sendErrorMessage($job, 'Nao encontrei essa assinatura para atualizar. Se quiser, eu posso listar suas assinaturas atuais primeiro.');

            return true;
        }

        $changedSchedule = false;

        if ($data['amount'] !== null) {
            $subscription->amount = $data['amount'];
        }

        if ($data['billing_cycle'] !== null && $data['billing_cycle'] !== $subscription->billing_cycle) {
            $subscription->billing_cycle = $data['billing_cycle'];
            $changedSchedule = true;
        }

        if ($data['due_day'] !== null && $data['due_day'] !== (int) $subscription->due_day) {
            $subscription->due_day = $data['due_day'];
            $changedSchedule = true;
        }

        [$bankAccount, $creditCard] = app(FinancialSourceResolver::class)->resolve($user, $data);
        if ($bankAccount !== null || $data['bank_account_name'] !== null) {
            $subscription->bank_account_id = $bankAccount?->id;
            if ($bankAccount !== null) {
                $subscription->credit_card_id = null;
            }
        }

        if ($creditCard !== null || $data['credit_card_name'] !== null) {
            $subscription->credit_card_id = $creditCard?->id;
            if ($creditCard !== null) {
                $subscription->bank_account_id = null;
            }
        }

        if ($changedSchedule) {
            $subscription->next_due_date = $subscription->calculateNextDueDate();
        }

        $subscription->save();

        $parts = [];
        if ($data['amount'] !== null) {
            $parts[] = 'valor de R$ '.number_format((float) $subscription->amount, 2, ',', '.');
        }
        if ($data['billing_cycle'] !== null) {
            $parts[] = 'ciclo '.($subscription->billing_cycle === 'yearly' ? 'anual' : 'mensal');
        }
        if ($data['due_day'] !== null) {
            $parts[] = 'vencimento no dia '.(int) $subscription->due_day;
        }
        if ($bankAccount !== null) {
            $parts[] = 'conta '.$bankAccount->name;
        } elseif ($creditCard !== null) {
            $parts[] = 'cartao '.$creditCard->name;
        }

        $reply = sprintf('Assinatura %s atualizada com %s.', $subscription->name, implode(', ', $parts));

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'subscriptions',
                'subscription_name' => $subscription->name,
            ],
        ]);

        $this->sendResponse($job, $reply, $user);

        return true;
    }

    private function normalizeData(array $data): array
    {
        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'billing_cycle' => $data['billing_cycle'] ?? null,
            'due_day' => isset($data['due_day']) ? (int) $data['due_day'] : null,
            'bank_account_name' => trim((string) ($data['bank_account_name'] ?? '')) ?: null,
            'credit_card_name' => trim((string) ($data['credit_card_name'] ?? '')) ?: null,
        ];
    }

    private function buildGuidanceReply(array $errors = []): string
    {
        $details = empty($errors) ? '' : "\n\nDetalhes: ".implode(' | ', $errors);

        return "Nao consegui atualizar essa assinatura com a mensagem atual.\n\n"
            ."Tente assim:\n"
            ."* ajustar assinatura Netflix para 25 reais\n"
            ."* editar assinatura Spotify dia 12\n"
            ."* mudar assinatura Academia para anual"
            .$details;
    }
}
