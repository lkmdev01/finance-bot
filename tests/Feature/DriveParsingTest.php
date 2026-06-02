<?php

namespace Tests\Feature;

use App\Services\WhatsApp\DriveIntentClassifier;
use App\Services\WhatsApp\DriveMessageParser;
use Tests\TestCase;

class DriveParsingTest extends TestCase
{
    public function test_drive_save_requires_media_in_state(): void
    {
        $parser = app(DriveMessageParser::class);

        $this->assertFalse($parser->looksLikeSaveIntent('salva isso no drive', []));
        $this->assertTrue($parser->looksLikeSaveWithoutMediaIntent('salva isso no drive', []));
    }

    public function test_drive_save_parses_folder_hint_when_present(): void
    {
        $parser = app(DriveMessageParser::class);
        $state = ['last_entities' => ['incoming_media_id' => 10]];

        $payload = $parser->parseSave('salva isso na pasta de comprovantes/veiculos', $state);

        $this->assertNotNull($payload);
        $this->assertEquals(10, $payload['incoming_media_id']);
        $this->assertEquals('comprovantes/veiculos', strtolower((string) $payload['folder_hint']));
    }

    public function test_drive_query_detects_document_search(): void
    {
        $classifier = app(DriveIntentClassifier::class);
        $state = [];

        $result = $classifier->classify('ache meu comprovante do mecanico desse ano', 'ache meu comprovante do mecanico desse ano', $state);

        $this->assertNotNull($result);
        $this->assertEquals('drive_query', $result['kind']);
    }

    public function test_does_not_misclassify_savings_language_as_drive(): void
    {
        $classifier = app(DriveIntentClassifier::class);
        $state = [];

        $message = 'Quero guardar 5 mil para viagem';
        $result = $classifier->classify($message, strtolower($message), $state);

        $this->assertNull($result);
    }
}
