<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConversationLog;
use Illuminate\Console\Command;

class PruneConversationLogs extends Command
{
    protected $signature = 'privacy:prune-conversation-logs {--days=}';

    protected $description = 'Remove conteúdo de conversas além do prazo de retenção';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('privacy.conversation_log_retention_days', 90)));
        $deleted = WhatsAppConversationLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("{$deleted} logs de conversa removidos (retenção: {$days} dias).");

        return self::SUCCESS;
    }
}
