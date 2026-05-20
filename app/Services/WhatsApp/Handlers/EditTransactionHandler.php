<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class EditTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'edit_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        if (!isset($result['transaction_id'])) {
            // Try to infer from description similar to delete handler
            $description = $result['transaction_data']['description'] ?? null;
            if ($description) {
                $transaction = Transaction::where('user_id', $user->id)
                    ->where('description', 'like', "%{$description}%")
                    ->latest()
                    ->first();
            } else {
                $transaction = Transaction::where('user_id', $user->id)->latest()->first();
            }
            if ($transaction) {
                $result['transaction_id'] = $transaction->id;
            } else {
                $this->sendErrorMessage($job, "⚠️ Não consegui identificar qual transação editar. Tente especificar melhor.");
                return true;
            }
        }

        $transaction = Transaction::where('user_id', $user->id)->where('id', $result['transaction_id'])->first();
        if (!$transaction) {
            $this->sendErrorMessage($job, "⚠️ Transação não encontrada.");
            return true;
        }

        // Prepare data to update
        $data = $result['transaction_data'] ?? [];
        // Validate similar to create transaction validation
        $validation = $this->validateTransactionData($data, $user);
        if ($validation->fails()) {
            $errors = $validation->errors()->all();
            $errorMsg = "⚠️ Dados inválidos para edição: " . implode(' | ', $errors);
            $this->sendErrorMessage($job, $errorMsg);
            return true;
        }

        // Apply updates
        $transaction->update([
            'amount' => $data['amount'] ?? $transaction->amount,
            'type' => $data['type'] ?? $transaction->type,
            'description' => $data['description'] ?? $transaction->description,
            'date' => $data['date'] ?? $transaction->date,
        ]);

        // Invalidate caches
        Cache::forget("user.{$user->id}.financial_data");
        Cache::forget("user.{$user->id}.financial_projections");

        Log::info('Transação editada via WhatsApp', [
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
        ]);

        $this->sendResponse($job, "✅ Transação editada com sucesso.", $user);
        return true;
    }

    private function validateTransactionData(array $data, User $user)
    {
        $rules = [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
        if (isset($data['category_id']) && $data['category_id'] !== null) {
            $rules['category_id'][] = function ($attribute, $value, $fail) use ($user) {
                $category = \App\Models\Category::where('id', $value)
                    ->where('user_id', $user->id)
                    ->first();
                if (! $category) {
                    $fail('A categoria selecionada não existe ou não pertence a você.');
                }
            };
        }
        return Validator::make($data, $rules);
    }
}
