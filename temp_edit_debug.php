<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\WhatsApp\ReminderMessageParser;
use App\Services\WhatsApp\IncomingMessageNormalizer;

$parser = app(ReminderMessageParser::class);
$normalizer = app(IncomingMessageNormalizer::class);

$message = 'editar lembrete tomar água para 25/05/2026 as 15:00';
$clean = (new ReflectionClass($parser))->getMethod('cleanText');
$clean->setAccessible(true);
$cleanText = $clean->invoke($parser, $message);

$partial = $parser->parsePartialCreate($message);
$titleExtract = $parser->extractTitle($message);
// Use EditReminderHandler's extractor to mimic handler behavior
$handler = app(App\Services\WhatsApp\Handlers\EditReminderHandler::class);
$extract = Closure::bind(function ($message) {
	return $this->extractReminderTitleFromMessage($message);
}, $handler, get_class($handler));
$handlerTitle = $extract($message);

echo "message={$message}\n";
echo "cleanText={$cleanText}\n";
echo "parser.extractTitle=" . var_export($titleExtract, true) . "\n";
echo "handler.extractTitle=" . var_export($handlerTitle, true) . "\n";
echo "parsePartialCreate=" . var_export($partial, true) . "\n";

echo "normalized message: " . $normalizer->normalize($message) . "\n";
