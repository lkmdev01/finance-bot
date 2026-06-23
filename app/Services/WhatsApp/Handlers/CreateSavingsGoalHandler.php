<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BillingPlanService;
use Illuminate\Support\Facades\Validator;

class CreateSavingsGoalHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_savings_goal';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $goalData = $this->normalizeGoalData($result['goal_data'] ?? []);
        $billingPlanService = app(BillingPlanService::class);

        if (! $billingPlanService->userCanCreateRecords($user)) {
            $plansUrl = rtrim((string) config('app.url'), '/').'/billing/plans';
            $reply = $billingPlanService->writeAccessMessage($user)
                ."\n\nAssine um plano para voltar a registrar novas informacoes:\n"
                .$plansUrl;
            $this->sendResponse($job, $reply, $user);

            return true;
        }

        $validation = Validator::make($goalData, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'target_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'target_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, $this->buildGuidanceReply($validation->errors()->all()));

            return true;
        }

        $goal = SavingsGoal::query()->create([
            'user_id' => $user->id,
            'name' => $goalData['name'],
            'description' => $goalData['description'] ?? null,
            'target_amount' => $goalData['target_amount'],
            'target_date' => $goalData['target_date'] ?? null,
            'is_completed' => false,
        ]);

        $reply = $this->buildCreatedReply($goal);
        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => array_filter([
                'topic' => 'savings',
                'goal_name' => $goal->name,
            ]),
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
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
        ];
    }

    private function buildCreatedReply(SavingsGoal $goal): string
    {
        $amount = number_format((float) $goal->target_amount, 2, ',', '.');

        if ($goal->target_date) {
            return sprintf(
                "Meta %s criada com valor de R$ %s.\n\nPrazo: %s\nProgresso inicial: R$ 0,00\n\nPara acompanhar depois, diga \"quais metas eu tenho?\".",
                $goal->name,
                $amount,
                $goal->target_date->format('d/m/Y')
            );
        }

        return sprintf(
            "Meta %s criada com valor de R$ %s.\n\nProgresso inicial: R$ 0,00\n\nPara acompanhar depois, diga \"quais metas eu tenho?\".",
            $goal->name,
            $amount
        );
    }

    private function buildGuidanceReply(array $errors = []): string
    {
        $details = empty($errors) ? '' : "\n\nDetalhes: ".implode(' | ', $errors);

        return "Nao consegui criar essa meta com a mensagem atual.\n\n"
            ."Tente assim:\n"
            ."- criar meta viagem com valor de 5000\n"
            ."- definir meta reserva de emergencia 10000\n"
            .'- criar nova meta carro com valor de 30000'
            .$details;
    }
}
