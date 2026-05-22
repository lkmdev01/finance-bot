<?php

// Script de debug para o bot WhatsApp
// Execute: php debug_bot.php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "====== DEBUG BOT ======\n\n";

// 1. Verificar se hay usuarios
$users = \App\Models\User::count();
echo "✓ Usuários no banco: $users\n";

// 2. Testar classificação de mensagem
$classifier = app(\App\Services\WhatsApp\IncomingMessageClassifier::class);
$test_message = "oi";
$classification = $classifier->classify($test_message);
echo "✓ Classificação de '$test_message': " . json_encode($classification) . "\n\n";

// 3. Testar reminder query
$reminder_classifier = app(\App\Services\WhatsApp\ReminderIntentClassifier::class);
$reminder_class = $reminder_classifier->classify('quais sao meus lembretes', 'quais sao meus lembretes', []);
echo "✓ Reminder query classification: " . json_encode($reminder_class) . "\n\n";

// 4. Listar routes
echo "✓ Verificando webhook...\n";
$routes = \Illuminate\Support\Facades\Route::getRoutes();
$webhook_routes = $routes->filter(function($route) {
    return str_contains($route->uri, 'webhook');
});
echo "Webhook routes: " . count($webhook_routes) . "\n";
foreach ($webhook_routes as $route) {
    echo "  - " . $route->methods[0] . " /" . $route->uri . "\n";
}
echo "\n";

// 5. Testar se a fila está funcionando
echo "✓ Verificando fila...\n";
echo "Queue default: " . config('queue.default') . "\n";
echo "Queue connection: " . config('queue.connections.' . config('queue.default') . '.driver') . "\n\n";

// 6. Testar modelo User
$user = \App\Models\User::first();
if ($user) {
    echo "✓ Usuário teste encontrado:\n";
    echo "  ID: " . $user->id . "\n";
    echo "  Phone: " . $user->phone . "\n";
    echo "  Name: " . $user->name . "\n\n";
} else {
    echo "⚠ Nenhum usuário encontrado no banco!\n\n";
}

// 7. Verificar logs
echo "✓ Últimas 10 linhas do log:\n";
$log_file = storage_path('logs/laravel.log');
if (file_exists($log_file)) {
    $lines = array_slice(file($log_file), -10);
    foreach ($lines as $line) {
        echo "  " . trim($line) . "\n";
    }
} else {
    echo "  Log file não encontrado!\n";
}
echo "\n";

// 8. Testar se handlers estão registrados
echo "✓ Verificando handlers...\n";
$factory = app(\App\Services\WhatsApp\ActionHandlerFactory::class);
echo "Factory criada com sucesso\n\n";

echo "====== FIM DEBUG ======\n";
