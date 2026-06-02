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

    public function test_drive_query_treats_generic_listing_as_query_without_term(): void
    {
        $parser = app(DriveMessageParser::class);

        $this->assertTrue($parser->looksLikeQueryIntent('quais arquivos eu tenho no drive', []));
        $this->assertNull($parser->extractQueryTerm('quais arquivos eu tenho no drive?'));
    }

    public function test_drive_query_parses_follow_up_and_time_scope(): void
    {
        $parser = app(DriveMessageParser::class);

        $folder = $parser->parseQuery('em qual pasta ficou?', ['last_entities' => ['topic' => 'drive']]);
        $today = $parser->parseQuery('quais arquivos eu salvei hoje?', ['last_entities' => ['topic' => 'drive']]);
        $openReference = $parser->parseQuery('abrir o 0424', ['last_entities' => ['topic' => 'drive']]);

        $this->assertEquals('show_folder', $folder['follow_up']);
        $this->assertEquals('today', $today['time_scope']);
        $this->assertTrue($today['list_mode']);
        $this->assertEquals('open_reference', $openReference['follow_up']);
        $this->assertEquals('0424', $openReference['open_reference']);
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
