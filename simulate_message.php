<?php
define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WhatsAppContact;
use App\Jobs\ProcessWhatsAppMessage;

$contact = WhatsAppContact::first();
if ($contact) {
    // Pass the actual user_id from the contact
    ProcessWhatsAppMessage::dispatch('5513991290256', 'Qual o meu saldo?', $contact->user_id, 'User');
    echo "Job dispatched for contact ID: " . $contact->id . " (User ID: " . $contact->user_id . ") with message 'Qual o meu saldo?'\n";
} else {
    echo "No contact found\n";
}
