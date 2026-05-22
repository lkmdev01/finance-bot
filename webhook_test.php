<?php

// Script simples para testar o webhook manualmente
// Execute: php webhook_test.php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "====== TESTE WEBHOOK ======\n\n";

// Simular uma mensagem de entrada
$payload = [
    'messages' => [
        [
            'id' => 'wamid.test123',
            'from' => '5511999999999',
            'timestamp' => time(),
            'type' => 'text',
            'text' => [
                'body' => 'oi'
            ]
        ]
    ],
    'contacts' => [
        [
            'profile' => [
                'name' => 'Teste User'
            ],
            'wa_id' => '5511999999999'
        ]
    ]
];

echo "Payload simulado:\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

try {
    // 1. Processar a mensagem
    echo "1️⃣ Processando mensagem...\n";

    $processor = app(\App\Services\WhatsApp\IncomingMessageProcessor::class);
    $result = $processor->process($payload['messages'][0], $payload['contacts'][0] ?? []);

    echo "   Status: " . json_encode($result) . "\n\n";

    // 2. Verificar se o job foi enfileirado
    echo "2️⃣ Verificando fila...\n";

    $jobs = \Illuminate\Support\Facades\Queue::connection()->jobs();
    echo "   Jobs na fila: $jobs\n\n";

    // 3. Listar handlers disponíveis
    echo "3️⃣ Handlers disponíveis:\n";
    $factory = app(\App\Services\WhatsApp\ActionHandlerFactory::class);
    echo "   ✓ Factory inicializada\n\n";

    echo "====== SUCESSO ======\n";

} catch (\Throwable $e) {
    echo "❌ ERRO:\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Stack:\n";
    foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 5) as $line) {
        echo "     $line\n";
    }
}
