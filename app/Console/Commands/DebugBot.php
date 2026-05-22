<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WhatsApp\ActionHandlerFactory;
use App\Services\WhatsApp\IncomingMessageClassifier;
use App\Services\WhatsApp\ReminderIntentClassifier;
use Illuminate\Console\Command;

class DebugBot extends Command
{
    protected $signature = 'debug:bot {--verbose : Mostrar informações detalhadas}';
    protected $description = 'Debug o bot WhatsApp para identificar problemas';

    public function handle(): int
    {
        $this->info('====== DEBUG BOT WHATSAPP ======');
        $this->newLine();

        // 1. Verificar usuários
        $this->checkUsers();

        // 2. Verificar classificação
        $this->checkClassification();

        // 3. Verificar handlers
        $this->checkHandlers();

        // 4. Verificar fila
        $this->checkQueue();

        // 5. Verificar logs
        $this->checkLogs();

        $this->info('====== FIM DEBUG ======');
        return 0;
    }

    private function checkUsers(): void
    {
        $this->info('📊 Verificando usuários...');
        $count = User::count();
        $this->line("  Total: $count usuários");

        if ($count === 0) {
            $this->warn('  ⚠️ Nenhum usuário encontrado!');
        } else {
            $user = User::first();
            $this->line("  Primeiro: ID={$user->id}, Phone={$user->phone}, Name={$user->name}");
        }
        $this->newLine();
    }

    private function checkClassification(): void
    {
        $this->info('🔍 Testando classificação de mensagens...');

        $classifier = app(IncomingMessageClassifier::class);
        $reminder_classifier = app(ReminderIntentClassifier::class);

        // Teste 1
        $result = $classifier->classify('oi');
        $this->line("  'oi' → " . json_encode($result));

        // Teste 2
        $result = $reminder_classifier->classify('quais sao meus lembretes', 'quais sao meus lembretes', []);
        $this->line("  'quais são meus lembretes' → " . json_encode($result));

        $this->newLine();
    }

    private function checkHandlers(): void
    {
        $this->info('⚙️ Verificando handlers...');

        try {
            $factory = app(ActionHandlerFactory::class);
            $this->line('  ✅ Factory registrada com sucesso');
        } catch (\Exception $e) {
            $this->error('  ❌ Erro ao carregar factory: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function checkQueue(): void
    {
        $this->info('📤 Verificando fila...');
        $this->line('  Driver: ' . config('queue.default'));
        $this->line('  Connection: ' . config('queue.connections.' . config('queue.default') . '.driver'));
        $this->newLine();
    }

    private function checkLogs(): void
    {
        $this->info('📋 Últimas linhas do log...');
        $logFile = storage_path('logs/laravel.log');

        if (!file_exists($logFile)) {
            $this->warn('  Log file não encontrado');
            return;
        }

        $lines = array_slice(file($logFile), -5);
        foreach ($lines as $line) {
            $this->line('  ' . trim($line));
        }
        $this->newLine();
    }
}
