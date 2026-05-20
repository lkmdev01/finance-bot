<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class DeleteTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'delete_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        // When transaction_id is provided directly
        if (isset($result['transaction_id'])) {
            $transactionId = $result['transaction_id'];
        } else {
            // Try to infer from description or last transaction
            $transactionId = null;
            if (!empty($result['transaction_data']['description'] ?? null)) {
                $description = $result['transaction_data']['description'];
                $transaction = Transaction::where('user_id', $user->id)
                    ->where('description', 'like', "%{$description}%")
                    ->latest()
                    ->first();
                if ($transaction) {
                    $transactionId = $transaction->id;
                }
            }
            if (!$transactionId) {
                $transaction = Transaction::where('user_id', $user->id)->latest()->first();
                if ($transaction) {
                    $transactionId = $transaction->id;
                }
            }
        }

        if (!$transactionId) {
            $errorMsg = "⚠️ Não consegui identificar qual transação deletar. Tente especificar melhor (ex: \"apagar última compra\" ou \"apagar gasto de R$ 50\").";
            $this->sendErrorMessage($job, $errorMsg);
            return true;
        }

        $transaction = Transaction::where('user_id', $user->id)->where('id', $transactionId)->first();
        if (!$transaction) {
            $this->sendErrorMessage($job, "⚠️ Transação não encontrada.");
            return true;
        }

        $transaction->delete();

        // Invalida cache
        Cache::forget("user.{$user->id}.financial_data");
        Cache::forget("user.{$user->id}.financial_projections");

        Log::info('Transação deletada via WhatsApp', [
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
        ]);

        $this->sendResponse($job, "✅ Transação deletada com sucesso.", $user);
        return true;
    }
}
