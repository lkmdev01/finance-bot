<?php

namespace Tests\Feature;

use App\Services\WhatsApp\NoteIntentClassifier;
use App\Services\WhatsApp\NoteMessageParser;
use Tests\TestCase;

class NotesParsingTest extends TestCase
{
    public function test_parses_note_create_from_anota_colon(): void
    {
        $parser = app(NoteMessageParser::class);
        $result = $parser->parseCreate('anota: tive uma ideia sobre o projeto de expansao');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertStringContainsString('tive uma ideia', strtolower((string) $result['body']));
        $this->assertEquals('whatsapp', $result['source']);
    }

    public function test_note_intent_classifier_returns_needs_content_when_missing_body(): void
    {
        $classifier = app(NoteIntentClassifier::class);
        $state = [];

        $result = $classifier->classify('anota', 'anota', $state);

        $this->assertNotNull($result);
        $this->assertEquals('note_needs_content', $result['kind']);
    }

    public function test_extracts_query_term_for_search(): void
    {
        $parser = app(NoteMessageParser::class);
        $term = $parser->extractQueryTerm('procura nota sobre contrato do projeto X');

        $this->assertNotNull($term);
        $this->assertStringContainsString('contrato', strtolower((string) $term));
    }

    public function test_minhas_notas_is_query_not_create(): void
    {
        $parser = app(NoteMessageParser::class);
        $classifier = app(NoteIntentClassifier::class);

        $message = 'minhas notas';
        $normalized = $message;

        $this->assertFalse($parser->looksLikeCreateIntent($normalized));
        $this->assertNull($parser->parseCreate($message));

        $result = $classifier->classify($message, $normalized, []);
        $this->assertNotNull($result);
        $this->assertEquals('note_query', $result['kind']);
    }

    public function test_quais_sao_minhas_notas_ativas_is_query_not_create(): void
    {
        $classifier = app(NoteIntentClassifier::class);

        $message = 'quais sao minhas notas ativas';
        $normalized = $message;

        $result = $classifier->classify($message, $normalized, []);
        $this->assertNotNull($result);
        $this->assertEquals('note_query', $result['kind']);
    }

    public function test_contextual_note_query_does_not_capture_gratitude(): void
    {
        $classifier = app(NoteIntentClassifier::class);

        $result = $classifier->classify('obrigado', 'obrigado', [
            'last_action' => 'query_notes',
            'last_entities' => ['topic' => 'notes'],
        ]);

        $this->assertNull($result);
    }

    public function test_contextual_note_query_accepts_named_follow_up(): void
    {
        $classifier = app(NoteIntentClassifier::class);

        $result = $classifier->classify('contrato fornecedor', 'contrato fornecedor', [
            'last_action' => 'query_notes',
            'last_entities' => ['topic' => 'notes'],
        ]);

        $this->assertNotNull($result);
        $this->assertEquals('note_query', $result['kind']);
    }
}
