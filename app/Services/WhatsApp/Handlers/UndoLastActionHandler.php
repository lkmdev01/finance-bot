<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Budget;
use App\Models\CreditCard;
use App\Models\Reminder;
use App\Models\RecurringTransaction;
use App\Models\SavingsGoal;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\ConversationStateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UndoLastActionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'undo_last_action';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $stateService = app(ConversationStateService::class);
        $state = $stateService->getState($contact);
        $stack = is_array($state['undo_stack'] ?? null) ? $state['undo_stack'] : [];

        if ($stack === []) {
            $this->sendResponse($job, 'Nao tenho nada para desfazer agora.', $user);
            return true;
        }

        [$entry, $remaining] = $this->popFirstValidEntry($stack);
        $stateService->replaceUndoStack($contact, $remaining);

        if ($entry === null) {
            $this->sendResponse($job, 'Nao tenho nada recente para desfazer agora.', $user);
            return true;
        }

        $handled = $this->applyUndo($entry, $user);

        if (! $handled['ok']) {
            $reply = $handled['reply'] ?? 'Nao consegui desfazer essa acao.';
            $this->sendErrorMessage($job, $reply);
            return true;
        }

        Cache::forget("user.{$user->id}.financial_data");
        Cache::forget("user.{$user->id}.financial_projections");

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => array_merge($result['_conversation_metadata']['entities'] ?? [], [
                'topic' => 'undo',
                'undo_kind' => $entry['kind'] ?? null,
            ]),
        ]);

        $this->sendResponse($job, (string) $handled['reply'], $user);
        return true;
    }

    private function popFirstValidEntry(array $stack): array
    {
        $remaining = $stack;
        while ($remaining !== []) {
            $entry = array_shift($remaining);
            if (! is_array($entry)) {
                continue;
            }

            if ($this->isExpired($entry)) {
                continue;
            }

            return [$entry, $remaining];
        }

        return [null, []];
    }

    private function isExpired(array $entry): bool
    {
        $expiresAt = $entry['expires_at'] ?? null;
        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            return Carbon::parse($expiresAt)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    private function applyUndo(array $entry, User $user): array
    {
        $kind = (string) ($entry['kind'] ?? '');

        try {
            return match ($kind) {
                'transaction_create' => $this->undoTransactionCreate($entry, $user),
                'transaction_update' => $this->undoTransactionUpdate($entry, $user),
                'transaction_delete' => $this->undoTransactionDelete($entry, $user),

                'budget_create' => $this->undoBudgetCreate($entry, $user),
                'budget_update' => $this->undoBudgetUpdate($entry, $user),
                'budget_delete' => $this->undoBudgetDelete($entry, $user),

                'subscription_create' => $this->undoGenericDelete(Subscription::class, $entry, $user, 'Assinatura desfeita: removi a assinatura criada.'),
                'subscription_update' => $this->undoGenericUpdate(Subscription::class, $entry, $user, 'Assinatura desfeita: voltei ao valor anterior.'),
                'subscription_cancel' => $this->undoGenericUpdate(Subscription::class, $entry, $user, 'Assinatura desfeita: reativei a assinatura.'),

                'credit_card_create' => $this->undoGenericDelete(CreditCard::class, $entry, $user, 'Cartao desfeito: removi o cartao criado.'),
                'credit_card_update' => $this->undoGenericUpdate(CreditCard::class, $entry, $user, 'Cartao desfeito: voltei ao limite anterior.'),

                'reminder_create' => $this->undoGenericDelete(Reminder::class, $entry, $user, 'Lembrete desfeito: removi o lembrete criado.'),
                'reminder_update' => $this->undoGenericUpdate(Reminder::class, $entry, $user, 'Lembrete desfeito: voltei ao estado anterior.'),
                'reminder_delete' => $this->undoGenericUpdate(Reminder::class, $entry, $user, 'Lembrete desfeito: reativei o lembrete.'),

                'recurring_create' => $this->undoGenericDelete(RecurringTransaction::class, $entry, $user, 'Recorrencia desfeita: removi a recorrencia criada.'),
                'recurring_update' => $this->undoGenericUpdate(RecurringTransaction::class, $entry, $user, 'Recorrencia desfeita: voltei ao estado anterior.'),
                'recurring_cancel' => $this->undoGenericUpdate(RecurringTransaction::class, $entry, $user, 'Recorrencia desfeita: reativei a recorrencia.'),

                'goal_create' => $this->undoGenericDelete(SavingsGoal::class, $entry, $user, 'Meta desfeita: removi a meta criada.'),
                'goal_update' => $this->undoGenericUpdate(SavingsGoal::class, $entry, $user, 'Meta desfeita: voltei ao estado anterior.'),
                default => [
                    'ok' => false,
                    'reply' => 'Nao encontrei uma acao valida para desfazer.',
                ],
            };
        } catch (\Throwable $e) {
            Log::warning('Falha ao desfazer ultima acao', [
                'user_id' => $user->id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'reply' => 'Nao consegui desfazer essa acao agora.',
            ];
        }
    }

    private function undoTransactionCreate(array $entry, User $user): array
    {
        $id = (int) ($entry['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'reply' => 'Nao encontrei a transacao para desfazer.'];
        }

        $deleted = Transaction::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->delete();

        return $deleted
            ? ['ok' => true, 'reply' => 'Desfeito. Apaguei a ultima transacao que eu tinha registrado.']
            : ['ok' => false, 'reply' => 'Nao encontrei essa transacao para apagar.'];
    }

    private function undoTransactionUpdate(array $entry, User $user): array
    {
        $id = (int) ($entry['id'] ?? 0);
        $before = is_array($entry['before'] ?? null) ? $entry['before'] : null;

        if ($id <= 0 || $before === null) {
            return ['ok' => false, 'reply' => 'Nao tenho dados suficientes para desfazer essa edicao.'];
        }

        $transaction = Transaction::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $transaction) {
            return ['ok' => false, 'reply' => 'Nao encontrei essa transacao para desfazer.'];
        }

        $transaction->forceFill($this->transactionRestorableAttributes($before))->save();

        return ['ok' => true, 'reply' => 'Desfeito. Voltei a transacao para o valor anterior.'];
    }

    private function undoTransactionDelete(array $entry, User $user): array
    {
        $attrs = is_array($entry['attributes'] ?? null) ? $entry['attributes'] : null;
        if ($attrs === null) {
            return ['ok' => false, 'reply' => 'Nao tenho dados suficientes para recuperar essa transacao.'];
        }

        $attrs = $this->transactionRestorableAttributes($attrs);
        $attrs['user_id'] = $user->id;

        $restored = Transaction::query()->create($attrs);

        return ['ok' => true, 'reply' => 'Desfeito. Eu recuperei a transacao apagada.'];
    }

    private function transactionRestorableAttributes(array $attrs): array
    {
        $allowed = [
            'category_id',
            'bank_account_id',
            'credit_card_id',
            'type',
            'amount',
            'description',
            'date',
            'metadata',
        ];

        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $attrs)) {
                $out[$key] = $attrs[$key];
            }
        }

        // Defensive: ensure metadata remains an array.
        if (isset($out['metadata']) && ! is_array($out['metadata'])) {
            $out['metadata'] = [];
        }

        return $out;
    }

    private function undoBudgetCreate(array $entry, User $user): array
    {
        $id = (int) ($entry['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'reply' => 'Nao encontrei o orcamento para desfazer.'];
        }

        $deleted = Budget::query()->where('id', $id)->where('user_id', $user->id)->delete();

        return $deleted
            ? ['ok' => true, 'reply' => 'Desfeito. Removi o orcamento que eu tinha criado.']
            : ['ok' => false, 'reply' => 'Nao encontrei esse orcamento para remover.'];
    }

    private function undoBudgetUpdate(array $entry, User $user): array
    {
        $id = (int) ($entry['id'] ?? 0);
        $before = is_array($entry['before'] ?? null) ? $entry['before'] : null;

        if ($id <= 0 || $before === null) {
            return ['ok' => false, 'reply' => 'Nao tenho dados suficientes para desfazer essa edicao de orcamento.'];
        }

        $budget = Budget::query()->where('id', $id)->where('user_id', $user->id)->first();
        if (! $budget) {
            return ['ok' => false, 'reply' => 'Nao encontrei esse orcamento para desfazer.'];
        }

        $budget->forceFill([
            'amount' => $before['amount'] ?? $budget->amount,
            'period' => $before['period'] ?? $budget->period,
            'year' => $before['year'] ?? $budget->year,
            'month' => $before['month'] ?? $budget->month,
        ])->save();

        return ['ok' => true, 'reply' => 'Desfeito. Voltei o orcamento para o valor anterior.'];
    }

    private function undoBudgetDelete(array $entry, User $user): array
    {
        $attrs = is_array($entry['attributes'] ?? null) ? $entry['attributes'] : null;
        if ($attrs === null) {
            return ['ok' => false, 'reply' => 'Nao tenho dados suficientes para recuperar esse orcamento.'];
        }

        $allowed = ['category_id', 'period', 'year', 'month', 'amount'];
        $create = ['user_id' => $user->id];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $attrs)) {
                $create[$k] = $attrs[$k];
            }
        }

        Budget::query()->create($create);

        return ['ok' => true, 'reply' => 'Desfeito. Eu recuperei o orcamento apagado.'];
    }

    private function undoGenericDelete(string $modelClass, array $entry, User $user, string $successReply): array
    {
        $id = (int) ($entry['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'reply' => 'Nao encontrei o registro para desfazer.'];
        }

        $deleted = $modelClass::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->delete();

        return $deleted ? ['ok' => true, 'reply' => $successReply] : ['ok' => false, 'reply' => 'Nao encontrei esse registro para remover.'];
    }

    private function undoGenericUpdate(string $modelClass, array $entry, User $user, string $successReply): array
    {
        $id = (int) ($entry['id'] ?? 0);
        $before = is_array($entry['before'] ?? null) ? $entry['before'] : null;
        if ($id <= 0 || $before === null) {
            return ['ok' => false, 'reply' => 'Nao tenho dados suficientes para desfazer essa alteracao.'];
        }

        $model = $modelClass::query()->where('id', $id)->where('user_id', $user->id)->first();
        if (! $model) {
            return ['ok' => false, 'reply' => 'Nao encontrei esse registro para desfazer.'];
        }

        $model->forceFill($before)->save();

        return ['ok' => true, 'reply' => $successReply];
    }

    private function undoGenericCreate(string $modelClass, array $entry, User $user, string $successReply): array
    {
        $attrs = is_array($entry['attributes'] ?? null) ? $entry['attributes'] : null;
        if ($attrs === null) {
            return ['ok' => false, 'reply' => 'Nao tenho dados suficientes para recuperar esse registro.'];
        }

        unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
        $attrs['user_id'] = $user->id;

        $modelClass::query()->create($attrs);

        return ['ok' => true, 'reply' => $successReply];
    }
}
