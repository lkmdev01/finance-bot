<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::find(1);
$contact = App\Models\WhatsAppContact::where('user_id', 1)->first();

$aiService = app(App\Services\AIService::class);
$result = $aiService->processMessage("Apagar gasto com ração", $user, $contact);

echo "Action: " . ($result['action'] ?? 'none') . "\n";
echo "Transaction ID: " . ($result['transaction_id'] ?? 'none') . "\n";
echo "Reply: " . ($result['reply'] ?? 'none') . "\n";
echo "\nFull result:\n";
print_r($result);
