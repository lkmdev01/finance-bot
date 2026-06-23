<?php

namespace Tests\Feature;

use App\Services\WhatsApp\DomainGate;
use App\Services\WhatsApp\DriveIntentClassifier;
use App\Services\WhatsApp\DriveMessageParser;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use App\Services\WhatsApp\ReminderMessageParser;
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
        $classifier = app(DriveIntentClassifier::class);

        $this->assertTrue($parser->looksLikeQueryIntent('quais arquivos eu tenho no drive', []));
        $this->assertNull($parser->extractQueryTerm('quais arquivos eu tenho no drive?'));

        $classified = $classifier->classify(
            'quais arquivos eu salvei hoje?',
            'quais arquivos eu salvei hoje',
            ['last_entities' => ['topic' => 'drive']]
        );

        $this->assertNotNull($classified);
        $this->assertEquals('drive_query', $classified['kind']);
    }

    public function test_drive_query_parses_follow_up_and_time_scope(): void
    {
        $parser = app(DriveMessageParser::class);

        $folder = $parser->parseQuery('em qual pasta ficou?', ['last_entities' => ['topic' => 'drive']]);
        $today = $parser->parseQuery('quais arquivos eu salvei hoje?', ['last_entities' => ['topic' => 'drive']]);
        $openReference = $parser->parseQuery('abrir o 0424', ['last_entities' => ['topic' => 'drive']]);
        $imageToday = $parser->parseQuery('procura a foto que eu mandei hoje', ['last_entities' => ['topic' => 'drive']]);
        $morePhotos = $parser->parseQuery('tem mais fotos?', ['last_entities' => ['topic' => 'drive']]);
        $onlyMatch = $parser->parseQuery('so essa?', ['last_entities' => ['topic' => 'drive']]);

        $this->assertEquals('show_folder', $folder['follow_up']);
        $this->assertEquals('today', $today['time_scope']);
        $this->assertTrue($today['list_mode']);
        $this->assertEquals('open_reference', $openReference['follow_up']);
        $this->assertEquals('0424', $openReference['open_reference']);
        $this->assertEquals('image', $imageToday['media_kind']);
        $this->assertEquals('today', $imageToday['time_scope']);
        $this->assertNull($imageToday['term']);
        $this->assertEquals('image', $morePhotos['media_kind']);
        $this->assertNull($morePhotos['term']);
        $this->assertEquals('only_match', $onlyMatch['follow_up']);
    }

    public function test_drive_query_removes_generic_media_words_when_specific_term_exists(): void
    {
        $parser = app(DriveMessageParser::class);

        $query = $parser->parseQuery('ache minha foto na neve', ['last_entities' => ['topic' => 'drive']]);

        $this->assertEquals('image', $query['media_kind']);
        $this->assertEquals('neve', $query['term']);
    }

    public function test_drive_query_accepts_specific_more_photos_without_previous_context(): void
    {
        $parser = app(DriveMessageParser::class);
        $classifier = app(DriveIntentClassifier::class);

        $this->assertTrue($parser->looksLikeQueryIntent('tem mais fotos da neve', []));

        $query = $parser->parseQuery('tem mais fotos da neve?', []);
        $result = $classifier->classify('tem mais fotos da neve?', 'tem mais fotos da neve', []);

        $this->assertEquals('image', $query['media_kind']);
        $this->assertEquals('neve', $query['term']);
        $this->assertNotNull($result);
        $this->assertEquals('drive_query', $result['kind']);
    }

    public function test_normalizes_common_photo_typo_for_drive_queries(): void
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $classifier = app(DriveIntentClassifier::class);

        $normalized = $normalizer->normalize('encontra a doto de banner');
        $result = $classifier->classify('encontra a doto de banner', $normalized, []);

        $this->assertStringContainsString('foto', $normalized);
        $this->assertNotNull($result);
        $this->assertEquals('drive_query', $result['kind']);
    }

    public function test_drive_query_preserves_uppercase_acronyms_even_when_they_are_stopwords(): void
    {
        $parser = app(DriveMessageParser::class);

        $query = $parser->parseQuery('Ache no drive a DAS que mandei hoje', ['last_entities' => ['topic' => 'drive']]);

        $this->assertEquals('das', $query['term']);
        $this->assertEquals('today', $query['time_scope']);
    }

    public function test_drive_contextual_follow_up_preserves_previous_filters(): void
    {
        $parser = app(DriveMessageParser::class);
        $state = [
            'last_entities' => [
                'topic' => 'drive',
                'drive_media_kind' => 'image',
                'drive_time_scope' => 'today',
            ],
        ];

        $morePhotos = $parser->parseQuery('tem mais fotos?', $state);
        $onlyMatch = $parser->parseQuery('so essa?', $state);

        $this->assertEquals('image', $morePhotos['media_kind']);
        $this->assertEquals('today', $morePhotos['time_scope']);
        $this->assertEquals('image', $onlyMatch['media_kind']);
        $this->assertEquals('today', $onlyMatch['time_scope']);
    }

    public function test_drive_context_accepts_only_match_as_a_valid_follow_up(): void
    {
        $parser = app(DriveMessageParser::class);
        $classifier = app(DriveIntentClassifier::class);
        $state = ['last_entities' => ['topic' => 'drive']];

        $this->assertTrue($parser->looksLikeQueryIntent('so essa?', $state));

        $result = $classifier->classify('so essa?', 'so essa', $state);

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

    public function test_drive_context_does_not_turn_generic_messages_into_drive_queries(): void
    {
        $parser = app(DriveMessageParser::class);
        $classifier = app(DriveIntentClassifier::class);
        $domainGate = app(DomainGate::class);
        $state = ['last_entities' => ['topic' => 'drive']];

        $this->assertFalse($parser->looksLikeQueryIntent('bom dia', $state));
        $this->assertFalse($parser->looksLikeQueryIntent('como voce pode me ajudar', $state));
        $this->assertEquals('general', $domainGate->detect('como voce pode me ajudar', $state));
        $this->assertNull($classifier->classify('como voce esta?', 'como voce esta', $state));
    }

    public function test_drive_context_accepts_tem_mais_fotos_as_a_valid_follow_up(): void
    {
        $parser = app(DriveMessageParser::class);
        $classifier = app(DriveIntentClassifier::class);
        $state = ['last_entities' => ['topic' => 'drive']];

        $this->assertTrue($parser->looksLikeQueryIntent('tem mais fotos?', $state));

        $result = $classifier->classify('tem mais fotos?', 'tem mais fotos', $state);

        $this->assertNotNull($result);
        $this->assertEquals('drive_query', $result['kind']);
    }

    public function test_drive_queries_with_today_are_not_misclassified_as_reminders(): void
    {
        $reminderParser = app(ReminderMessageParser::class);

        $this->assertFalse($reminderParser->looksLikeCreateIntent('quais arquivos eu salvei hoje'));
        $this->assertFalse($reminderParser->looksLikeCreateIntent('procura a foto que eu mandei hoje'));
        $this->assertFalse($reminderParser->looksLikeCreateIntent('encontra o audio sobre o projeto'));
    }
}
