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
}

