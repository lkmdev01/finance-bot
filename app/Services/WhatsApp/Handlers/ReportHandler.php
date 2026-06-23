<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppResponseBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReportHandler extends BaseHandler
{
    private const REPORT_ACTIONS = [
        'query_report',
        'query_report_pdf',
        'query_report_csv',
        'query_report_excel',
    ];

    public function canHandle(?string $action): bool
    {
        return in_array($action, self::REPORT_ACTIONS, true);
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        try {
            $period = 'monthly';
            $selectedMonth = now()->format('Y-m');
            $year = now()->year;

            if (preg_match('/\b(ano|anual|yearly)\b/i', $job->message)) {
                $period = 'yearly';
            }

            if (preg_match('/\b(janeiro|fevereiro|marco|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s+(\d{4})\b/iu', $job->message, $matches)) {
                $monthMap = [
                    'janeiro' => '01',
                    'fevereiro' => '02',
                    'marco' => '03',
                    'março' => '03',
                    'abril' => '04',
                    'maio' => '05',
                    'junho' => '06',
                    'julho' => '07',
                    'agosto' => '08',
                    'setembro' => '09',
                    'outubro' => '10',
                    'novembro' => '11',
                    'dezembro' => '12',
                ];

                $month = $monthMap[mb_strtolower($matches[1])] ?? null;
                $year = (int) $matches[2];

                if ($month) {
                    $selectedMonth = "{$year}-{$month}";
                }
            }

            $format = match ($action) {
                'query_report_csv' => 'csv',
                'query_report_excel' => 'excel',
                default => 'pdf',
            };

            $reportUrl = match ($format) {
                'excel' => route('reports.export.excel', compact('period', 'selectedMonth', 'year')),
                'csv' => route('transactions.export.csv', compact('period', 'selectedMonth', 'year')),
                default => route('reports.export.pdf', compact('period', 'selectedMonth', 'year')),
            };

            $formatName = match ($format) {
                'csv' => 'CSV',
                'excel' => 'Excel',
                default => 'PDF',
            };

            $periodName = $period === 'monthly' ? 'mes atual' : "ano de {$year}";
            if ($selectedMonth !== now()->format('Y-m') && $period === 'monthly') {
                $periodName = 'mes '.Carbon::createFromFormat('Y-m', $selectedMonth)->translatedFormat('m/Y');
            }

            $reply = app(WhatsAppResponseBuilder::class)->success(
                "Seu relatorio {$formatName} esta pronto.",
                [
                    'Periodo' => $periodName,
                    'Link para abrir ou baixar' => $reportUrl,
                ],
                ['gerar em PDF', 'gerar em Excel', 'relatorio do mes passado']
            );

            $this->sendResponse($job, $reply, $user);

            Log::info('Relatorio gerado via WhatsApp', [
                'user_id' => $user->id,
                'format' => $format,
                'period' => $period,
                'selectedMonth' => $selectedMonth,
                'year' => $year,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erro ao gerar relatorio via WhatsApp', [
                'user_id' => $user->id,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            $this->sendErrorMessage(
                $job,
                app(WhatsAppResponseBuilder::class)->guidance(
                    'Nao consegui gerar o relatorio agora.',
                    ['me gera um relatorio do mes', 'me manda o relatorio em PDF', 'relatorio anual em Excel']
                )
            );
        }

        return true;
    }
}
