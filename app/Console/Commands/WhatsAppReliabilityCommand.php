<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWhatsAppReply;
use App\Models\WhatsAppMessageReceipt;
use App\Models\WhatsAppOutboundMessage;
use Illuminate\Console\Command;

class WhatsAppReliabilityCommand extends Command
{
    protected $signature = 'whatsapp:reliability
        {--mark-stale : Mark interrupted processing and uncertain deliveries for manual review}
        {--retry-outbound= : Retry only delivery of a pending or failed outbound message by ID}
        {--allow-ambiguous : Allow a reviewed ambiguous delivery to be retried after checking the provider}';

    protected $description = 'Audit inbound processing and outbound WhatsApp delivery without exposing message content.';

    public function handle(): int
    {
        if ($this->option('mark-stale')) {
            $this->markStale();
        }

        if ($this->option('retry-outbound') !== null) {
            $result = $this->retryOutbound((string) $this->option('retry-outbound'));
            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->line(sprintf(
            'Entradas: recebidas=%d, processando=%d, concluidas=%d, revisar=%d',
            WhatsAppMessageReceipt::where('status', WhatsAppMessageReceipt::RECEIVED)->count(),
            WhatsAppMessageReceipt::where('status', WhatsAppMessageReceipt::PROCESSING)->count(),
            WhatsAppMessageReceipt::where('status', WhatsAppMessageReceipt::COMPLETED)->count(),
            WhatsAppMessageReceipt::where('status', WhatsAppMessageReceipt::NEEDS_REVIEW)->count(),
        ));
        $this->line(sprintf(
            'Saidas: pendentes=%d, enviando=%d, entregues=%d, falhas=%d, revisar=%d',
            WhatsAppOutboundMessage::where('status', 'pending')->count(),
            WhatsAppOutboundMessage::where('status', 'sending')->count(),
            WhatsAppOutboundMessage::where('status', 'delivered')->count(),
            WhatsAppOutboundMessage::where('status', 'failed')->count(),
            WhatsAppOutboundMessage::where('status', 'needs_review')->count(),
        ));

        $inboundIds = WhatsAppMessageReceipt::where('status', WhatsAppMessageReceipt::NEEDS_REVIEW)
            ->orderBy('id')->limit(20)->pluck('id')->implode(', ');
        $outboundIds = WhatsAppOutboundMessage::whereIn('status', ['failed', 'needs_review'])
            ->orderBy('id')->limit(20)->pluck('id')->implode(', ');

        $this->line('Entradas para revisar (IDs): '.($inboundIds !== '' ? $inboundIds : 'nenhuma'));
        $this->line('Saidas para revisar (IDs): '.($outboundIds !== '' ? $outboundIds : 'nenhuma'));

        return self::SUCCESS;
    }

    private function markStale(): void
    {
        $staleInbound = WhatsAppMessageReceipt::query()
            ->where('status', WhatsAppMessageReceipt::PROCESSING)
            ->where('processing_started_at', '<=', now()->subMinutes(15))
            ->update([
                'status' => WhatsAppMessageReceipt::NEEDS_REVIEW,
                'review_reason' => 'worker_interrupted',
                'updated_at' => now(),
            ]);

        $staleOutbound = WhatsAppOutboundMessage::query()
            ->where('status', 'sending')
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->update(['status' => 'needs_review', 'updated_at' => now()]);

        $this->line("Marcadas para revisao: {$staleInbound} entradas e {$staleOutbound} saidas.");
    }

    private function retryOutbound(string $id): int
    {
        if (! ctype_digit($id) || (int) $id < 1) {
            $this->error('Informe um ID numerico valido para --retry-outbound.');

            return self::FAILURE;
        }

        $outbound = WhatsAppOutboundMessage::find((int) $id);
        if ($outbound === null) {
            $this->error('Saida nao encontrada.');

            return self::FAILURE;
        }

        if ($outbound->status === 'delivered' || $outbound->status === 'sending') {
            $this->error('Essa saida ja foi entregue ou ainda esta em envio.');

            return self::FAILURE;
        }

        if ($outbound->status === 'needs_review' && ! $this->option('allow-ambiguous')) {
            $this->error('Entrega incerta: confirme no Baileys se a mensagem chegou antes de usar --allow-ambiguous.');

            return self::FAILURE;
        }

        $outbound->update(['status' => 'pending']);
        DeliverWhatsAppReply::dispatch($outbound->id);
        $this->info("Reenvio da saida {$outbound->id} enfileirado, sem reprocessar a entrada.");

        return self::SUCCESS;
    }
}
