<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;
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
            $period        = 'monthly';
            $selectedMonth = now()->format('Y-m');
            $year          = now()->year;

            // Detecta período anual
            if (preg_match('/\b(ano|anual|yearly)\b/i', $job->message)) {
                $period = 'yearly';
            }

            // Detecta mês/ano específico (ex: "março 2025")
            if (preg_match('/\b(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s+(\d{4})\b/i', $job->message, $matches)) {
                $monthMap = [
                    'janeiro' => '01', 'fevereiro' => '02', 'março'    => '03',
                    'abril'   => '04', 'maio'      => '05', 'junho'    => '06',
                    'julho'   => '07', 'agosto'    => '08', 'setembro' => '09',
                    'outubro' => '10', 'novembro'  => '11', 'dezembro' => '12',
                ];
                $month = $monthMap[strtolower($matches[1])] ?? null;
                $year  = (int) $matches[2];
                if ($month) {
                    $selectedMonth = "{$year}-{$month}";
                }
            }

            // Formato do relatório
            $format = match ($action) {
                'query_report_pdf'   => 'pdf',
                'query_report_csv'   => 'csv',
                'query_report_excel' => 'excel',
                default              => 'pdf',
            };

            // URL do relatório
            $reportUrl = match ($format) {
                'excel' => route('reports.export.excel', compact('period', 'selectedMonth', 'year')),
                'csv'   => route('transactions.export.csv', compact('period', 'selectedMonth', 'year')),
                default => route('reports.export.pdf', compact('period', 'selectedMonth', 'year')),
            };

            $formatName = match ($format) {
                'csv'   => 'CSV',
                'excel' => 'Excel',
                default => 'PDF',
            };

            $periodName = $period === 'monthly' ? 'mês atual' : "ano de {$year}";

            if ($selectedMonth !== now()->format('Y-m') && $period === 'monthly') {
                $periodName = 'mês ' . \Carbon\Carbon::createFromFormat('Y-m', $selectedMonth)->translatedFormat('m/Y');
            }

            $reply = "📊 Seu relatório {$formatName} está pronto.\n\n"
                   . "📅 Período: {$periodName}\n"
                   . "🔗 Link para abrir ou baixar:\n{$reportUrl}\n\n"
                   . 'Se quiser, eu também posso gerar esse relatório em outro formato.';

            $this->sendResponse($job, $reply, $user);

            Log::info('Relatório gerado via WhatsApp', [
                'user_id'       => $user->id,
                'format'        => $format,
                'period'        => $period,
                'selectedMonth' => $selectedMonth,
                'year'          => $year,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório via WhatsApp', [
                'user_id' => $user->id,
                'action'  => $action,
                'error'   => $e->getMessage(),
            ]);

            $this->sendErrorMessage($job, '❌ Não consegui gerar o relatório. Tente novamente em alguns instantes.');
        }

        return true;
    }
}
