<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Validator;

class UpdateSavingsGoalHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'update_savings_goal';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $goalData = $this->normalizeGoalData($result['goal_data'] ?? []);

        $validation = Validator::make($goalData, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'target_amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'target_date' => ['nullable', 'date'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, $this->buildGuidanceReply($validation->errors()->all()));

            return true;
        }

        $goal = $user->savingsGoals()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($goalData['name'])])
            ->first();

        if (! $goal instanceof SavingsGoal) {
            $this->sendErrorMessage($job, 'Nao encontrei essa meta para atualizar. Se quiser, eu posso listar suas metas atuais primeiro.');

            return true;
        }

        $goal->fill(array_filter([
            'target_amount' => $goalData['target_amount'],
            'target_date' => $goalData['target_date'],
        ], fn ($value) => $value !== null));
        $goal->save();

        $amountPart = $goalData['target_amount'] !== null
            ? ' novo valor de R$ '.number_format((float) $goal->target_amount, 2, ',', '.')
            : '';
        $datePart = $goalData['target_date'] ? ' e prazo ate '.$goal->target_date?->format('d/m/Y') : '';

        $reply = sprintf('Meta %s atualizada com%s%s.', $goal->name, $amountPart, $datePart);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'savings',
                'goal_name' => $goal->name,
            ],
        ]);

        $this->sendResponse($job, $reply, $user);

        return true;
    }

    private function normalizeGoalData(array $data): array
    {
        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'target_amount' => isset($data['target_amount']) ? (float) $data['target_amount'] : null,
            'target_date' => isset($data['target_date']) && $data['target_date'] !== '' ? (string) $data['target_date'] : null,
        ];
    }

    private function buildGuidanceReply(array $errors = []): string
    {
        $details = empty($errors) ? '' : "\n\nDetalhes: ".implode(' | ', $errors);

        return "Nao consegui atualizar essa meta com a mensagem atual.\n\n"
            ."Tente assim:\n"
            ."* ajustar meta viagem para 7000\n"
            ."* editar meta viagem europa para 1500\n"
            ."* atualizar meta reserva para 10000 em dezembro 2026"
            .$details;
    }
}
