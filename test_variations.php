<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\WhatsAppContact;
use App\Models\User;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = User::first();
$contact = WhatsAppContact::where('user_id', $user->id)->first();

if (!$user || !$contact) {
    echo "Usuário ou contato não encontrado.\n";
    exit(1);
}

$variations = [
    "Recebi o salario no valor de 15000",
    "Gastei 50 com pizza",
    "Qual meu saldo?",
    "Gera um relatório PDF",
    "Como estão meus gastos esse mês?",
    "Qual foi minha última compra?",
    "Apaga o gasto com pizza"
];

echo "🚀 Iniciando teste de variações...\n";

foreach ($variations as $message) {
    echo "📤 Enviando: \"{$message}\"\n";
    // Dispara o job de forma síncrona para ver o resultado no log imediatamente ou capturar exceções
    try {
        ProcessWhatsAppMessage::dispatchSync('5513991290256', $message, $user->id, 'Lukas Martins');
        echo "✅ Job despachado com sucesso.\n";
    } catch (\Exception $e) {
        echo "❌ ERRO: " . $e->getMessage() . "\n";
    }
    // Pausa maior para evitar rate limit de tokens por minuto (TPM) do Groq
    sleep(10); 
}

echo "\n✨ Teste concluído. Verifique o laravel.log para ver as respostas da IA.\n";
