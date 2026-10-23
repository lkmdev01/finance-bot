<?php

namespace App\Services\Meetings;

use App\Jobs\PollMeetingStatus;
use App\Jobs\RequestMeetingBot;
use App\Models\Meeting;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MeetingCommandService
{
    public function __construct(
        private readonly MeetingUrlParser $parser,
        private readonly VexaApiClient $vexa,
    ) {}

    public function handle(string $message, User $user, WhatsAppContact $contact): ?string
    {
        $plain = Str::of($message)->ascii()->lower()->squish()->toString();
        if (preg_match('/^(?:status|estado) (?:da )?reuniao$/u', $plain)) {
            return $this->status($user, true);
        }
        if (preg_match('/^(?:resumo|relatorio) (?:da )?reuniao$/u', $plain)) {
            return $this->report($user);
        }
        if (preg_match('/^(?:reuni[aã]o|iniciar reuni[aã]o)\s+(https:\/\/\S+)\s+participantes\s+avisados\s+e\s+autorizo$/iu', trim($message), $match)) {
            return $this->start(html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $user, $contact);
        }
        if (preg_match('/^(?:reuniao|iniciar reuniao)(?:\s|$)/u', $plain)) {
            return 'Para iniciar: *reunião https://link-da-reuniao participantes avisados e autorizo*. Confirme somente depois de avisar todos os participantes de que o bot entrará, gravará a reunião e o áudio será transcrito posteriormente.';
        }
        return null;
    }

    private function start(string $url, User $user, WhatsAppContact $contact): string
    {
        if (! config('vexa.enabled', false)) {
            return 'O agente de reuniões está indisponível no momento. Seus relatórios anteriores continuam disponíveis.';
        }
        if (blank(config('vexa.base_url')) || blank(config('vexa.api_key'))) {
            return 'A integração de reuniões ainda não está configurada. Peça ao suporte para configurar o Vexa.';
        }
        try {
            $parsed = $this->parser->parse($url);
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }
        if (! $this->vexa->isAvailable()) {
            return 'O serviço de reuniões está temporariamente indisponível. Tente novamente mais tarde.';
        }
        $lookupHash = hash_hmac('sha256', $parsed['platform']."\0".$parsed['native_meeting_id'], (string) config('app.key'));
        try {
            $meeting = Cache::lock('meeting-link:'.$lookupHash, 15)->block(5, function () use ($user, $contact, $parsed, $url, $lookupHash) {
                $active = Meeting::query()->where('platform', $parsed['platform'])
                    ->where('native_meeting_id_hash', $lookupHash)
                    ->whereIn('status', ['queued', 'requesting', 'requested', 'joining', 'awaiting_admission', 'needs_help', 'active', 'stopping', 'completed', 'awaiting_transcription', 'transcribing', 'analyzing'])
                    ->first();
                if ($active && (int) $active->user_id !== (int) $user->id) {
                    return null;
                }
                $existing = Meeting::query()->where('user_id', $user->id)
                ->where('platform', $parsed['platform'])->where('native_meeting_id_hash', $lookupHash)
                ->whereIn('status', ['queued', 'requesting', 'requested', 'joining', 'awaiting_admission', 'needs_help', 'active', 'stopping', 'completed', 'awaiting_transcription', 'transcribing', 'analyzing', 'reported', 'needs_review'])
                ->lockForUpdate()->first();
                if ($existing) return $existing;
                return DB::transaction(fn () => Meeting::create([
                'user_id' => $user->id,
                'whats_app_contact_id' => $contact->id,
                'platform' => $parsed['platform'],
                'native_meeting_id_hash' => $lookupHash,
                'native_meeting_id' => $parsed['native_meeting_id'],
                'meeting_url' => $url,
                'status' => 'queued',
                'participant_notice_attested' => true,
                'participant_notice_attested_at' => now(),
                ]));
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return 'Não consegui registrar a reunião agora. Tente novamente em instantes.';
        }
        if ($meeting === null) return 'Esse link já está registrado para uma solicitação de reunião em andamento.';
        if ($meeting->wasRecentlyCreated) {
            RequestMeetingBot::dispatch($meeting->id);
            return 'Solicitei a entrada do bot do Vexa. A reunião será gravada e transcrita posteriormente; enviarei o resumo quando estiver pronto.';
        }
        if ($meeting->status === 'reported') return 'Essa reunião já foi processada. Envie *resumo da reunião* para receber o relatório novamente.';
        if ($meeting->status === 'needs_review') return 'Essa reunião precisa de revisão porque a transcrição não trouxe conteúdo suficiente para um relatório confiável.';
        return 'Essa reunião já está registrada e em andamento. Envie *status da reunião* para consultar o estado.';
    }

    private function status(User $user, bool $poll): string
    {
        $meeting = Meeting::query()->where('user_id', $user->id)->latest()->first();
        if (! $meeting) return 'Não encontrei reuniões registradas para sua conta.';
        if ($meeting->status === 'expired') return 'O histórico desta reunião expirou conforme a política de retenção.';
        $effectiveStatus = $this->effectiveStatus($meeting);
        if ($poll && in_array($meeting->status, ['queued', 'requesting', 'requested', 'joining', 'awaiting_admission', 'needs_help', 'active', 'stopping', 'completed'], true)) {
            PollMeetingStatus::dispatch($meeting->id);
        }
        if ($effectiveStatus === 'needs_review') {
            return 'A transcrição ficou vazia ou não trouxe conteúdo suficiente para um relatório confiável. A reunião precisa de revisão.';
        }
        return 'Status da reunião mais recente: *'.$this->statusLabel($effectiveStatus).'*.'.($meeting->failure_code ? ' Precisa de verificação operacional.' : '');
    }

    private function report(User $user): string
    {
        $meeting = Meeting::query()->where('user_id', $user->id)->latest()->first();
        if (! $meeting) return 'Não encontrei reuniões registradas para sua conta.';
        if ($meeting->status === 'expired') return 'O histórico desta reunião expirou conforme a política de retenção.';
        $status = $this->effectiveStatus($meeting);
        if ($status === 'needs_review') return 'Não foi possível confirmar conteúdo suficiente na transcrição para preparar um relatório confiável. A reunião precisa de revisão.';
        if (filled($meeting->summary)) return $meeting->summary;
        if ($status === 'completed') {
            PollMeetingStatus::dispatch($meeting->id);
            return 'A reunião terminou e está aguardando a transcrição local. Enviarei o resumo quando estiver pronto.';
        }
        if (in_array($status, ['awaiting_transcription', 'transcribing'], true)) {
            return match ($status) {
                'awaiting_transcription' => 'A reunião está aguardando a transcrição local. Enviarei o resumo quando estiver pronto.',
                default => 'A reunião está sendo transcrita localmente. Enviarei o resumo quando estiver pronto.',
            };
        }
        return 'Ainda não há resumo disponível. O status atual é *'.$this->statusLabel($status).'*. Enviarei o relatório quando a reunião terminar e a transcrição estiver pronta.';
    }

    private function effectiveStatus(Meeting $meeting): string
    {
        $task = $meeting->transcriptionTask;
        if ($task?->status === 'processing' && $task->lease_expires_at?->isPast()) {
            return $task->attempts >= $task->max_attempts ? 'needs_review' : 'awaiting_transcription';
        }
        return $meeting->status;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'na fila', 'requesting' => 'solicitando entrada do bot',
            'requested' => 'bot solicitado', 'joining' => 'entrando',
            'awaiting_admission' => 'aguardando admissão do anfitrião',
            'needs_help' => 'precisa de ajuda para entrar', 'active' => 'em andamento',
            'stopping' => 'encerrando', 'completed' => 'aguardando transcrição',
            'awaiting_transcription' => 'aguardando transcrição', 'transcribing' => 'transcrevendo', 'analyzing' => 'gerando resumo',
            'reported' => 'concluída e resumida', 'failed' => 'falhou', 'needs_review' => 'precisa de revisão',
            'expired' => 'histórico expirado',
            default => $status,
        };
    }
}
