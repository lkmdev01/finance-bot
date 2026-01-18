<?php

use App\Models\User;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\WhatsAppContact;
use App\Jobs\ProcessWhatsAppMessage;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\PhoneNumberService;
use App\Services\PerformanceMetricsService;
use App\Services\WhatsAppMessageProcessor;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$phoneNumber = '5513991290256';
$user = User::where('id', 1)->first();
$contact = WhatsAppContact::firstOrCreate(
    ['phone_number' => $phoneNumber, 'user_id' => $user->id],
    ['name' => 'Lukas Test']
);

echo "🧪 Iniciando Bateria de Testes de Mensagens...\n";
echo "==============================================\n";

function testMessage($text) {
    global $phoneNumber, $user, $contact;
    
    echo "\n💬 Usuário: \"$text\"\n";
    
    $processor = app(WhatsAppMessageProcessor::class);
    $result = $processor->process($text, $user, $contact);
    
    echo "🤖 Resposta IA: " . $result['reply'] . "\n";
    echo "⚡ Ação: " . ($result['action'] ?? 'none') . "\n";

    $job = new ProcessWhatsAppMessage(
        phoneNumber: $phoneNumber,
        message: $text,
        userId: $user->id
    );

    $aiService = app(AIService::class);
    $baileysService = app(BaileysService::class);
    $phoneNumberService = app(PhoneNumberService::class);
    $metricsService = app(PerformanceMetricsService::class);

    try {
        $job->handle($aiService, $baileysService, $phoneNumberService, $metricsService);
        
        // Verifica o que aconteceu no banco
        $lastTransaction = Transaction::where('user_id', $user->id)->latest()->first();
        if ($lastTransaction && str_contains($text, (string)round($lastTransaction->amount))) {
             echo "✅ Transação Salva: " . $lastTransaction->description . " | R$ " . number_format($lastTransaction->amount, 2) . "\n";
             echo "📁 Categoria atribuída: " . ($lastTransaction->category->name ?? 'Nenhuma') . " " . ($lastTransaction->category->icon ?? '') . "\n";
        } else {
             echo "⚠️ Transação não encontrada ou valor divergente.\n";
        }
        
    } catch (\Exception $e) {
        echo "❌ Erro no Job: " . $e->getMessage() . "\n";
    }
    echo "----------------------------------------------\n";
    sleep(2); // Pequena pausa para evitar rate limit na Groq
}

// Scenários solicitados/planejados
testMessage("Lanche no shopping 45 reais");                 // Categoria existente (Alimentação)
testMessage("Gastei 200 com ração de cachorro e banho");    // Categoria NOVA (deve sugerir Pets/Animais)
testMessage("Pizza de ontem 50 reais");                     // Teste específico do erro reportado
testMessage("Recebi 1200 de um freela de design");          // Receita (Freelance ou nova categoria)
testMessage("Paguei o curso de inglês 350,00");             // Categoria Educação (pode ser nova ou existente)

echo "\n✨ Testes finalizados. Verifique os resultados acima.\n";
