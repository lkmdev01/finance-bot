<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Note;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BillingPlanService;
use Illuminate\Support\Facades\Validator;

class CreateNoteHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_note';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $billingPlanService = app(BillingPlanService::class);
        if (! $billingPlanService->userCanCreateRecords($user)) {
            $plansUrl = rtrim((string) config('app.url'), '/').'/billing/plans';
            $reply = $billingPlanService->writeAccessMessage($user)
                ."\n\nAssine aqui:\n{$plansUrl}";
            $this->sendResponse($job, $reply, $user);

            return true;
        }

        $data = $this->normalizeData($result['note_data'] ?? []);

        $validation = Validator::make($data, [
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'body' => ['required', 'string', 'min:2'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage(
                $job,
                "Nao consegui salvar essa nota.\n\nTente assim:\n"
                ."- anota: ideia para o projeto X\n"
                .'- nota: lembrar de falar com Joao sobre o contrato'
            );

            return true;
        }

        $note = Note::query()->create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'source' => $data['source'] ?: 'whatsapp',
            'metadata' => $data['metadata'] ?? [],
        ]);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => [
                'kind' => 'note_create',
                'id' => $note->id,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
            'entities' => [
                'topic' => 'notes',
                'note_id' => $note->id,
                'note_title' => $note->title,
            ],
        ]);

        $panelUrl = rtrim((string) config('app.url'), '/').'/notes';

        $reply = "Nota salva.\n\nTitulo: *{$note->title}*\n\nPara consultar depois, diga:\n- minhas notas\n- abrir nota 1\n- procura nota sobre <tema>\n\nPainel: {$panelUrl}";
        $this->sendResponse($job, $reply, $user);

        return true;
    }

    private function normalizeData(array $data): array
    {
        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'body' => trim((string) ($data['body'] ?? '')),
            'source' => isset($data['source']) ? trim((string) $data['source']) : null,
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        ];
    }
}
