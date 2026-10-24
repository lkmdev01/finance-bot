<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\Meetings\MeetingVexaReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileVexaMeetings extends Command
{
    protected $signature = 'meetings:reconcile-vexa';

    protected $description = 'Reconcile active MeetingAgent records with Vexa lifecycle state';

    public function handle(MeetingVexaReconciliationService $reconciliation): int
    {
        if (! config('vexa.enabled', false)) {
            $this->info('Vexa meetings are disabled; reconciliation skipped.');

            return self::SUCCESS;
        }

        $checked = 0;
        Meeting::query()
            ->where(function ($query): void {
                $query->whereIn('status', ['requesting', 'requested', 'joining', 'connecting', 'awaiting_admission', 'needs_help', 'active', 'stopping', 'completed'])
                    ->orWhere(function ($failed): void {
                        $failed->where('status', 'failed')
                            ->where(function ($failure): void {
                                $failure->whereNull('failure_code')->orWhere('failure_code', '!=', 'vexa_meeting_failed');
                            });
                    });
            })
            ->whereNotNull('vexa_meeting_id')
            ->chunkById(50, function ($meetings) use ($reconciliation, &$checked): void {
                foreach ($meetings as $meeting) {
                    $checked++;
                    try {
                        $reconciliation->reconcile($meeting);
                    } catch (Throwable $exception) {
                        Log::error('Erro inesperado ao reconciliar reunião Vexa', [
                            'meeting_id' => $meeting->id,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        Log::info('Varredura de reconciliação Vexa concluída', ['meetings_checked' => $checked]);
        $this->info("Reconciled {$checked} active or stopping meeting(s).");

        return self::SUCCESS;
    }
}
