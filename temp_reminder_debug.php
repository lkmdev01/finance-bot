<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reminder;
use App\Models\User;
use App\Services\WhatsApp\Handlers\DeleteReminderHandler;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use App\Services\WhatsApp\ReminderMessageParser;

$unique = uniqid();
$user = User::create(['name' => 'Debug User', 'email' => "debug+{$unique}@example.com", 'password' => bcrypt('secret'), 'phone_number' => '551399' . rand(100000000, 999999999)]);
$reminder = Reminder::create([
    'user_id' => $user->id,
    'title' => 'Falar Com João',
    'message' => 'Lembrete pontual: Falar Com João',
    'frequency' => 'once',
    'timezone' => config('app.timezone'),
    'next_trigger_at' => now()->addDay(),
    'trigger_time' => '09:00:00',
    'is_active' => true,
]);

$msg = 'apague o lembrete falar com joão';
$handler = app(DeleteReminderHandler::class);
$extract = Closure::bind(function ($message) {
    return $this->extractReminderTitleFromMessage($message);
}, $handler, get_class($handler));
$title = $extract($msg);

$normalizer = new IncomingMessageNormalizer();
$search = $normalizer->normalize($title);

$reminders = Reminder::where('user_id', $user->id)->where('is_active', true)->get();
$exactMatches = $reminders->filter(function ($reminder) use ($normalizer, $search) {
    return $normalizer->normalize($reminder->title) === $search;
});
$containsMatches = $reminders->filter(function ($reminder) use ($normalizer, $search) {
    return str_contains($normalizer->normalize($reminder->title), $search);
});

echo "msg={$msg}\n";
echo "title={$title}\n";
echo "search={$search}\n";
echo "stored={$reminder->title}\n";
echo "storedNormalized=" . $normalizer->normalize($reminder->title) . "\n";
echo "exactCount=" . $exactMatches->count() . "\n";
echo "containsCount=" . $containsMatches->count() . "\n";
foreach ($containsMatches as $match) {
    echo "match=" . $match->title . "\n";
}
